<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\JobInformation;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EmployeeStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->hr = User::factory()->create(['email' => 'hr-stats@example.com', 'is_active' => true]);
        $this->hr->syncRoles(['hr']);
    }

    private function makeEmp(string $email, Store $store, array $jobOverrides = [], string $role = 'employee', bool $userActive = true): EmployeeProfile
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => $userActive]);
        $user->syncRoles([$role]);

        $profile = EmployeeProfile::create([
            'user_id' => $user->id,
            'code' => 'EMP202611'.rand(1000, 9999),
            'identity_number' => '3171'.rand(100000000000, 999999999999),
            'phone' => '0812'.rand(10000000, 99999999),
            'date_of_birth' => '1994-05-05',
            'gender' => 'male',
            'place_of_birth' => 'Jakarta',
            'address' => 'Jl. Test',
            'city' => 'Jakarta',
            'postal_code' => '10110',
        ]);

        JobInformation::create(array_merge([
            'employee_id' => $profile->id,
            'job_title' => 'Staff',
            'store_id' => $store->id,
            'years_experience' => 2,
            'status' => 'active',
            'employment_type' => 'full_time',
            'work_location' => 'store',
            'start_date' => now()->subMonths(3)->toDateString(),
            'monthly_salary' => 6000000,
            'skill_level' => 'intermediate',
        ], $jobOverrides));

        return $profile;
    }

    public function test_employee_statistics_definitions_store_filter_and_realtime_updates(): void
    {
        Sanctum::actingAs($this->hr);

        $storeA = Store::create(['name' => 'Store A', 'type' => 'store', 'brand' => 'Toys City', 'city' => 'Jakarta', 'is_active' => true]);
        $storeB = Store::create(['name' => 'Store B', 'type' => 'store', 'brand' => 'Toys City', 'city' => 'Bandung', 'is_active' => true]);

        // 1. Active internal employee (start_date 3 months ago, salary 6,000,000) -> INCLUDED
        $this->makeEmp('int1@example.com', $storeA, ['monthly_salary' => 6000000]);

        // 2. Active internal new hire (start_date THIS month, salary 10,000,000) -> INCLUDED + New Hire
        $this->makeEmp('newhire@example.com', $storeA, [
            'monthly_salary' => 10000000,
            'start_date' => now()->startOfMonth()->toDateString(),
        ]);

        // 3. Active vendor employee (salary 1,000,000) -> INCLUDED in total/vendor_count, EXCLUDED from avg salary
        $this->makeEmp('vendor@example.com', $storeB, [
            'employment_type' => 'vendor',
            'vendor_name' => 'PT Vendor',
            'monthly_salary' => 1000000,
        ]);

        // 4. Resigned employee -> EXCLUDED
        $this->makeEmp('resigned@example.com', $storeA, ['status' => 'resigned', 'monthly_salary' => 20000000]);

        // 5. Soft-deleted employee -> EXCLUDED
        $deleted = $this->makeEmp('deleted@example.com', $storeA, ['monthly_salary' => 30000000]);
        $deleted->delete();

        // 6. Super Admin with profile -> EXCLUDED
        $this->makeEmp('sa@example.com', $storeA, ['monthly_salary' => 50000000], 'super-admin');

        // Global stats: total = 3 (internal=2, vendor=1), avg_salary = (6M + 10M) / 2 = 8,000,000, new_hires = 1
        $res = $this->getJson('/api/v1/employees/statistics')->assertStatus(200);
        $res->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.internal_count', 2)
            ->assertJsonPath('data.vendor_count', 1)
            ->assertJsonPath('data.new_employees', 1)
            ->assertJsonPath('data.period_label', 'This month: '.now()->format('M Y'));
        $this->assertEquals(8000000.0, (float) $res->json('data.average_salary'));

        // Filter by store_id=storeB -> total=1 (vendor=1, internal=0), avg_salary=0
        $resB = $this->getJson("/api/v1/employees/statistics?store_id={$storeB->id}")->assertStatus(200);
        $resB->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.internal_count', 0)
            ->assertJsonPath('data.vendor_count', 1);
        $this->assertEquals(0.0, (float) $resB->json('data.average_salary'));

        // Real-time update (no stale cache): add another active internal employee with salary 14,000,000
        $this->makeEmp('int2@example.com', $storeA, ['monthly_salary' => 14000000]);
        $resAfter = $this->getJson('/api/v1/employees/statistics')->assertStatus(200);
        $resAfter->assertJsonPath('data.total', 4);
        $this->assertEquals(10000000.0, (float) $resAfter->json('data.average_salary'));
    }
}

<?php

namespace Tests\Feature;

use App\Enums\LeaveType;
use App\Models\Attendance;
use App\Models\EmployeeProfile;
use App\Models\JobInformation;
use App\Models\LeaveRequest;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StoreHeadScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->hr = User::factory()->create(['email' => 'hr-scope@example.com', 'is_active' => true]);
        $this->hr->syncRoles(['hr']);
    }

    private function createStaff(string $name, string $email, Store $store, string $role = 'employee'): EmployeeProfile
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email, 'is_active' => true]);
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

        JobInformation::create([
            'employee_id' => $profile->id,
            'job_title' => 'Cashier Staff',
            'store_id' => $store->id,
            'years_experience' => 2,
            'status' => 'active',
            'employment_type' => 'full_time',
            'work_location' => 'store',
            'start_date' => now()->subMonth()->toDateString(),
            'monthly_salary' => 5000000,
            'skill_level' => 'intermediate',
        ]);

        return $profile;
    }

    public function test_store_head_dashboard_search_and_direct_access_are_scoped_to_own_store_while_hr_sees_all(): void
    {
        Sanctum::actingAs($this->hr);

        $storeA = Store::find($this->postJson('/api/v1/stores', [
            'name' => 'Toys City Alpha',
            'brand' => 'Toys City',
            'city' => 'Jakarta',
        ])->assertStatus(201)->json('data.id'));

        $storeB = Store::find($this->postJson('/api/v1/stores', [
            'name' => 'Toys City Beta',
            'brand' => 'Toys City',
            'city' => 'Bandung',
        ])->assertStatus(201)->json('data.id'));

        $headA = $this->createStaff('Alpha Head', 'heada@example.com', $storeA, 'manager');
        $storeA->update(['head_user_id' => $headA->user_id]);

        $empA = $this->createStaff('Zebra Alpha Staff', 'empa@example.com', $storeA);
        $empB = $this->createStaff('Zebra Beta Staff', 'empb@example.com', $storeB);

        $teamA = Team::where('store_id', $storeA->id)->firstOrFail();
        $teamB = Team::where('store_id', $storeB->id)->firstOrFail();

        Attendance::create([
            'employee_id' => $empA->id,
            'date' => now()->toDateString(),
            'check_in' => now()->setTime(9, 0),
            'status' => 'present',
        ]);
        Attendance::create([
            'employee_id' => $empB->id,
            'date' => now()->toDateString(),
            'check_in' => now()->setTime(9, 0),
            'status' => 'present',
        ]);

        LeaveRequest::create([
            'employee_id' => $empB->id,
            'leave_type' => LeaveType::cases()[0]->value,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'total_days' => 2,
            'reason' => 'Zebra Beta Leave',
            'status' => 'pending',
        ]);

        // 1. Act as Kepala Toko of Store A
        Sanctum::actingAs($headA->user);

        // Dashboard statistics count ONLY Store A (headA + empA = 2 employees, 6 teams)
        $dashRes = $this->getJson('/api/v1/dashboard/statistics')->assertStatus(200);
        $this->assertSame(2, (int) $dashRes->json('data.employees.total'));
        $this->assertSame(6, (int) $dashRes->json('data.teams.total'));

        // Global search for "Zebra" returns Store A staff only, NEVER Store B staff or leave
        $searchRes = $this->getJson('/api/v1/search?q=Zebra')->assertStatus(200);
        $titles = collect($searchRes->json('data.items'))->pluck('title')->all();
        $this->assertContains('Zebra Alpha Staff', $titles);
        $this->assertNotContains('Zebra Beta Staff', $titles);
        $this->assertFalse(collect($titles)->contains(fn ($t) => str_contains($t, 'Zebra Beta')));

        // Direct ID access to Store B employee, team, and store -> 403
        $this->getJson("/api/v1/employees/{$empB->id}")->assertStatus(403);
        $this->getJson("/api/v1/teams/{$teamB->id}")->assertStatus(403);
        $this->getJson("/api/v1/stores/{$storeB->id}")->assertStatus(403);

        // Direct ID access to Store A employee, team, and store -> 200
        $this->getJson("/api/v1/employees/{$empA->id}")->assertStatus(200);
        $this->getJson("/api/v1/teams/{$teamA->id}")->assertStatus(200);
        $this->getJson("/api/v1/stores/{$storeA->id}")->assertStatus(200);

        // 2. Act as HR -> sees BOTH Store A and Store B in search & direct access
        Sanctum::actingAs($this->hr);
        $hrSearch = $this->getJson('/api/v1/search?q=Zebra')->assertStatus(200);
        $hrTitles = collect($hrSearch->json('data.items'))->pluck('title')->all();
        $this->assertContains('Zebra Alpha Staff', $hrTitles);
        $this->assertContains('Zebra Beta Staff', $hrTitles);
        $this->getJson("/api/v1/employees/{$empB->id}")->assertStatus(200);
    }
}

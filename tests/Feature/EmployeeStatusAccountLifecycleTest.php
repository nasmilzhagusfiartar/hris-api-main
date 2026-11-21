<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureUserIsActive;
use App\Interfaces\PayrollRepositoryInterface;
use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EmployeeStatusAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createEmployee(string $email, float $salary = 6300000): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('Secret123!'),
            'is_active' => true,
        ]);
        $user->syncRoles(['employee']);

        $profile = $user->employeeProfile()->create([
            'code' => 'EMP'.rand(100000, 999999),
            'identity_number' => '3171'.rand(100000000000, 999999999999),
            'phone' => '0812'.rand(10000000, 99999999),
            'date_of_birth' => '1995-01-01',
            'gender' => 'male',
            'place_of_birth' => 'Jakarta',
            'address' => 'Jakarta',
            'city' => 'Jakarta',
            'postal_code' => '10110',
        ]);

        $profile->jobInformation()->create([
            'job_title' => 'Staff',
            'department' => 'sales',
            'years_experience' => 2,
            'status' => 'active',
            'employment_type' => 'full_time',
            'work_location' => 'office',
            'start_date' => '2025-01-01',
            'monthly_salary' => $salary,
            'skill_level' => 'intermediate',
        ]);

        return [$user, $profile];
    }

    public function test_resigned_status_deactivates_account_revokes_tokens_and_reactivating_restores_login(): void
    {
        $hr = User::factory()->create(['email' => 'hr-status@example.com', 'is_active' => true]);
        $hr->syncRoles(['hr']);

        [$empUser, $empProfile] = $this->createEmployee('resign-emp@example.com');

        // 1. Employee logs in before resignation
        $loginRes = $this->postJson('/api/v1/login', [
            'email' => 'resign-emp@example.com',
            'password' => 'Secret123!',
        ])->assertStatus(200);
        $oldToken = $loginRes->json('data.token');

        // 2. HR changes status to resigned without end_date -> 422 validation error
        Sanctum::actingAs($hr);
        $this->putJson("/api/v1/employees/{$empProfile->id}", [
            'status' => 'resigned',
        ])->assertStatus(422);

        // 3. HR changes status to resigned WITH end_date -> 200 & account deactivated
        $this->putJson("/api/v1/employees/{$empProfile->id}", [
            'status' => 'resigned',
            'end_date' => '2026-11-05',
        ])->assertStatus(200);

        $empUser->refresh();
        $this->assertFalse((bool) $empUser->is_active);
        $this->assertSame(0, $empUser->tokens()->count());

        // 4. Old token rejected
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/v1/me')
            ->assertStatus(401);

        // 5. Fresh login rejected with 403
        $this->postJson('/api/v1/login', [
            'email' => 'resign-emp@example.com',
            'password' => 'Secret123!',
        ])->assertStatus(403)
            ->assertJsonPath('message', EnsureUserIsActive::DEACTIVATED_MESSAGE);

        // 6. Audit Log recorded account_deactivated with reason "status changed to resigned"
        if (Schema::hasTable('telescope_entries')) {
            $hasLog = DB::table('telescope_entries')
                ->where('content', 'like', '%account_deactivated%')
                ->where('content', 'like', '%status changed to resigned%')
                ->exists();
            $this->assertTrue($hasLog);
        }

        // 7. Resigned employee does NOT appear in active team candidate query
        Sanctum::actingAs($hr);
        $activeList = $this->getJson('/api/v1/employees?status=active')->assertStatus(200);
        $ids = collect($activeList->json('data'))->pluck('id')->all();
        $this->assertNotContains($empProfile->id, $ids);

        // 8. HR reactivates status back to active -> login succeeds again
        $this->putJson("/api/v1/employees/{$empProfile->id}", [
            'status' => 'active',
        ])->assertStatus(200);

        $empUser->refresh();
        $this->assertTrue((bool) $empUser->is_active);
        $this->postJson('/api/v1/login', [
            'email' => 'resign-emp@example.com',
            'password' => 'Secret123!',
        ])->assertStatus(200);
    }

    public function test_resigned_employee_is_prorated_until_end_date_and_excluded_from_next_period_payroll(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 20, 10, 0, 0));

        // Active coworker so December payroll still has an eligible employee
        $this->createEmployee('still-active@example.com', 5000000);
        [$resignedUser, $resignedProfile] = $this->createEmployee('mid-resign@example.com', 6300000);

        // Period Nov 2026 = 2026-10-16 .. 2026-11-15 (21 Mon-Fri working days).
        // Resigns effective 2026-10-30 -> 11 Mon-Fri working days (Oct 16,19,20,21,22,23,26,27,28,29,30).
        $resignedProfile->jobInformation->update([
            'status' => 'resigned',
            'end_date' => '2026-10-30',
        ]);

        // Employee attended all 11 working days up to 2026-10-30
        $c = Carbon::parse('2026-10-16');
        $attendedDates = 0;
        while ($c->lte(Carbon::parse('2026-10-30'))) {
            if ($c->dayOfWeekIso <= 5) {
                Attendance::create([
                    'employee_id' => $resignedProfile->id,
                    'date' => $c->format('Y-m-d'),
                    'status' => 'present',
                ]);
                $attendedDates++;
            }
            $c->addDay();
        }
        $this->assertSame(11, $attendedDates);

        $repo = app(PayrollRepositoryInterface::class);

        // Current period (Nov 2026: 16 Oct - 15 Nov): prorated 11/21 of 6,300,000 = 3,300,000
        $novPayroll = $repo->generatePayroll('2026-11');
        $novDetail = $novPayroll->payrollDetails->firstWhere('employee_id', $resignedProfile->id);
        $this->assertNotNull($novDetail);
        $this->assertSame(11, (int) $novDetail->working_days);
        $this->assertSame(0, (int) $novDetail->absent_days);
        $this->assertEquals(3300000.0, (float) $novDetail->final_salary);

        // Next period (Dec 2026: 16 Nov - 15 Dec): end_date (2026-10-30) < 2026-11-16 -> EXCLUDED!
        $decPayroll = $repo->generatePayroll('2026-12');
        $decDetail = $decPayroll->payrollDetails->firstWhere('employee_id', $resignedProfile->id);
        $this->assertNull($decDetail);
    }
}

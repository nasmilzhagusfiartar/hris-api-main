<?php

use App\Enums\Department;
use App\Enums\TeamStatus;
use App\Models\Attendance;
use App\Models\BreachCheck;
use App\Models\EmployeeProfile;
use App\Models\JobInformation;
use App\Models\LeaveRequest;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);
    Queue::fake();
    Http::fake([
        '*' => Http::response(['breaches' => []], 404),
    ]);

    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);
    $this->seed(SecuritySeeder::class);
});

test('SecuritySeeder creates security@gmail.com with security role and no attendance permissions', function () {
    $securityUser = User::where('email', 'security@gmail.com')->first();

    expect($securityUser)->not->toBeNull();
    expect($securityUser->hasRole('security'))->toBeTrue();
    expect($securityUser->can('cti.brand-protection.view'))->toBeTrue();
    expect($securityUser->can('cti.email-breach.check-any'))->toBeTrue();
    expect($securityUser->can('audit-log.view'))->toBeTrue();

    // Must NOT have any attendance permissions
    expect($securityUser->can('attendance-list'))->toBeFalse();
    expect($securityUser->can('attendance-check-in'))->toBeFalse();
    expect($securityUser->can('attendance-check-out'))->toBeFalse();
    expect($securityUser->can('attendance-my-attendances'))->toBeFalse();
    expect($securityUser->can('attendance-last-attendance'))->toBeFalse();
});

test('all six roles enforce Brand Protection, Audit Log, and Email Breach access matrix', function () {
    $admin = User::factory()->create(['email' => 'admin@gmail.com']);
    $admin->assignRole('admin');

    $security = User::where('email', 'security@gmail.com')->firstOrFail();

    $manager = User::factory()->create(['email' => 'manager@gmail.com']);
    $manager->assignRole('manager');

    $hr = User::factory()->create(['email' => 'hr@gmail.com']);
    $hr->assignRole('hr');

    $finance = User::factory()->create(['email' => 'finance@gmail.com']);
    $finance->assignRole('finance');

    $employee = User::factory()->create(['email' => 'employee@gmail.com']);
    $employee->assignRole('employee');

    BreachCheck::create([
        'email' => 'manager@gmail.com',
        'is_breached' => false,
        'breaches' => [],
        'checked_at' => now(),
    ]);
    BreachCheck::create([
        'email' => 'employee@gmail.com',
        'is_breached' => true,
        'breaches' => ['TestBreach2025'],
        'checked_at' => now(),
    ]);

    foreach ([$admin, $security] as $fullCtiUser) {
        Sanctum::actingAs($fullCtiUser);

        $this->getJson('/api/v1/cti/brand-scans/latest')->assertStatus(200);
        $this->postJson('/api/v1/cti/brand-scans', ['domain' => 'herointiputra.com'])->assertStatus(202);
        $this->getJson('/api/v1/cti/audit-logs')->assertStatus(200);
        $this->getJson('/api/v1/dashboard/security-statistics')->assertStatus(200);
        $this->postJson('/api/v1/cti/breach-checks/check-all')->assertStatus(202);
        $this->postJson('/api/v1/cti/breach-checks', ['email' => 'someone.else@herointiputra.com'])->assertStatus(200);

        $historyRes = $this->getJson('/api/v1/cti/breach-checks/all/paginated');
        $historyRes->assertStatus(200);
        expect($historyRes->json('data.meta.total'))->toBeGreaterThanOrEqual(2);
    }

    foreach ([$manager, $hr, $finance, $employee] as $limitedUser) {
        Sanctum::actingAs($limitedUser);

        $this->getJson('/api/v1/cti/brand-scans/latest')->assertStatus(403);
        $this->postJson('/api/v1/cti/brand-scans', ['domain' => 'herointiputra.com'])->assertStatus(403);
        $this->getJson('/api/v1/cti/audit-logs')->assertStatus(403);
        $this->getJson('/api/v1/dashboard/security-statistics')->assertStatus(403);
        $this->postJson('/api/v1/cti/breach-checks/check-all')->assertStatus(403);

        $this->postJson('/api/v1/cti/breach-checks', ['email' => 'other.person@herointiputra.com'])
            ->assertStatus(403);

        $this->postJson('/api/v1/cti/breach-checks', ['email' => $limitedUser->email])
            ->assertStatus(200)
            ->assertJsonPath('data.email', $limitedUser->email);

        $ownHistory = $this->getJson('/api/v1/cti/breach-checks/all/paginated');
        $ownHistory->assertStatus(200);
        $items = $ownHistory->json('data.data');
        expect($items)->not->toBeEmpty();
        foreach ($items as $item) {
            expect(strtolower($item['email']))->toBe(strtolower($limitedUser->email));
        }
    }
});

test('Security role receives 403 on all attendance and HRIS management endpoints and is excluded from HR attendance recaps', function () {
    $security = User::where('email', 'security@gmail.com')->firstOrFail();
    Sanctum::actingAs($security);

    // Every attendance endpoint must return 403 for Security
    $this->getJson('/api/v1/attendances')->assertStatus(403);
    $this->getJson('/api/v1/attendances/all/paginated')->assertStatus(403);
    $this->getJson('/api/v1/attendances/statistics')->assertStatus(403);
    $this->getJson('/api/v1/attendances/last-attendance')->assertStatus(403);
    $this->getJson('/api/v1/my-attendances')->assertStatus(403);
    $this->getJson('/api/v1/my-attendance-statistics')->assertStatus(403);
    $this->postJson('/api/v1/attendances/check-in', ['lat' => -6.2, 'long' => 106.8])->assertStatus(403);
    $this->postJson('/api/v1/attendances/check-out', ['lat' => -6.2, 'long' => 106.8])->assertStatus(403);

    // Blocked from HRIS management endpoints & global HRIS dashboard statistics
    $this->getJson('/api/v1/employees')->assertStatus(403);
    $this->getJson('/api/v1/teams')->assertStatus(403);
    $this->getJson('/api/v1/projects')->assertStatus(403);
    $this->getJson('/api/v1/payrolls')->assertStatus(403);
    $this->getJson('/api/v1/leave-requests')->assertStatus(403);
    $this->getJson('/api/v1/reports/attendance')->assertStatus(403);
    $this->getJson('/api/v1/dashboard/statistics')->assertStatus(403);

    // Allowed own profile and leave requests
    $this->getJson('/api/v1/my-profile')->assertStatus(200);
    $this->getJson('/api/v1/my-leave-requests')->assertStatus(200);

    // Verify HR attendance report and attendance recap do NOT include Security user
    $hr = User::factory()->create(['email' => 'hr.recap@gmail.com']);
    $hr->assignRole('hr');
    Sanctum::actingAs($hr);

    $reportRes = $this->getJson('/api/v1/reports/attendance');
    $reportRes->assertStatus(200);
    $summaryEmails = collect($reportRes->json('data.employees_summary'))->pluck('email')->all();
    expect($summaryEmails)->not->toContain('security@gmail.com');
});

test('dashboard statistics and quick action counts filter data by role scope', function () {
    $hr = User::factory()->create(['email' => 'hr.dash@gmail.com']);
    $hr->assignRole('hr');

    $managerA = User::factory()->create(['email' => 'mgr.a@gmail.com']);
    $managerA->assignRole('manager');

    $managerB = User::factory()->create(['email' => 'mgr.b@gmail.com']);
    $managerB->assignRole('manager');

    $finance = User::factory()->create(['email' => 'fin.dash@gmail.com']);
    $finance->assignRole('finance');

    $employee = User::factory()->create(['email' => 'emp.dash@gmail.com']);
    $employee->assignRole('employee');

    $teamA = Team::create([
        'name' => 'Team A',
        'icon' => 'team-icons/activity.png',
        'expected_size' => 5,
        'department' => Department::IT,
        'status' => TeamStatus::ACTIVE,
        'team_lead_id' => $managerA->id,
        'responsibilities' => ['Dev'],
    ]);

    $teamB = Team::create([
        'name' => 'Team B',
        'icon' => 'team-icons/activity.png',
        'expected_size' => 5,
        'department' => Department::IT,
        'status' => TeamStatus::ACTIVE,
        'team_lead_id' => $managerB->id,
        'responsibilities' => ['Ops'],
    ]);

    $empUser1 = User::factory()->create(['email' => 'emp1@gmail.com']);
    $empProfile1 = EmployeeProfile::create([
        'user_id' => $empUser1->id,
        'code' => 'E1',
        'identity_number' => '1001',
        'phone' => '0811',
        'date_of_birth' => '1995-01-01',
        'gender' => 'male',
        'place_of_birth' => 'Jakarta',
        'address' => 'Jakarta',
        'city' => 'Jakarta',
        'postal_code' => '10110',
    ]);
    JobInformation::create([
        'employee_id' => $empProfile1->id,
        'job_title' => 'Dev',
        'team_id' => $teamA->id,
        'years_experience' => 1,
        'status' => 'active',
        'employment_type' => 'full_time',
        'work_location' => 'office',
        'start_date' => '2024-01-01',
        'monthly_salary' => 8000000,
        'skill_level' => 'junior',
    ]);
    TeamMember::create(['team_id' => $teamA->id, 'employee_id' => $empProfile1->id, 'joined_at' => now()]);

    $empUser2 = User::factory()->create(['email' => 'emp2@gmail.com']);
    $empProfile2 = EmployeeProfile::create([
        'user_id' => $empUser2->id,
        'code' => 'E2',
        'identity_number' => '1002',
        'phone' => '0812',
        'date_of_birth' => '1995-02-02',
        'gender' => 'female',
        'place_of_birth' => 'Jakarta',
        'address' => 'Jakarta',
        'city' => 'Jakarta',
        'postal_code' => '10110',
    ]);
    JobInformation::create([
        'employee_id' => $empProfile2->id,
        'job_title' => 'Ops',
        'team_id' => $teamB->id,
        'years_experience' => 1,
        'status' => 'active',
        'employment_type' => 'full_time',
        'work_location' => 'office',
        'start_date' => '2024-01-01',
        'monthly_salary' => 8000000,
        'skill_level' => 'junior',
    ]);
    TeamMember::create(['team_id' => $teamB->id, 'employee_id' => $empProfile2->id, 'joined_at' => now()]);

    // Create pending leave request for Team B employee
    LeaveRequest::create([
        'employee_id' => $empProfile2->id,
        'leave_type' => 'annual_leave',
        'start_date' => now()->addDay()->toDateString(),
        'end_date' => now()->addDays(2)->toDateString(),
        'total_days' => 2,
        'reason' => 'Cuti',
        'status' => 'pending',
    ]);

    // 1. Manager A only sees 1 employee in Team A and 0 pending leave requests (since pending leave is in Team B)
    Sanctum::actingAs($managerA);
    $mgrStats = $this->getJson('/api/v1/dashboard/statistics');
    $mgrStats->assertStatus(200)
        ->assertJsonPath('data.scope', 'team')
        ->assertJsonPath('data.employees.total', 1)
        ->assertJsonPath('data.teams.total', 1);

    $mgrCounts = $this->getJson('/api/v1/dashboard/quick-action-counts');
    $mgrCounts->assertStatus(200)
        ->assertJsonPath('data.pending_leave_requests', 0);

    // 2. HR sees all 2 non-security employees and 1 pending leave request
    Sanctum::actingAs($hr);
    $hrStats = $this->getJson('/api/v1/dashboard/statistics');
    $hrStats->assertStatus(200)
        ->assertJsonPath('data.scope', 'global')
        ->assertJsonPath('data.employees.total', 2);

    $hrCounts = $this->getJson('/api/v1/dashboard/quick-action-counts');
    $hrCounts->assertStatus(200)
        ->assertJsonPath('data.pending_leave_requests', 1);

    // 3. Finance receives finance-scoped stats (no global teams)
    Sanctum::actingAs($finance);
    $finStats = $this->getJson('/api/v1/dashboard/statistics');
    $finStats->assertStatus(200)
        ->assertJsonPath('data.scope', 'finance')
        ->assertJsonPath('data.teams.total', 0);

    // 4. Employee is forbidden from /api/v1/dashboard/statistics (uses /api/v1/dashboard/my-statistics)
    Sanctum::actingAs($employee);
    $this->getJson('/api/v1/dashboard/statistics')->assertStatus(403);
});

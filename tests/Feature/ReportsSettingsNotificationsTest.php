<?php

use App\Enums\Department;
use App\Enums\TeamStatus;
use App\Models\Attendance;
use App\Models\EmployeeProfile;
use App\Models\JobInformation;
use App\Models\LeaveRequest;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\LeaveRequestCreated;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);
});

test('manager only sees attendance report for employees in their managed team while HR sees all employees', function () {
    $hrUser = User::factory()->create(['name' => 'HR User', 'email' => 'hr.test@example.com']);
    $hrUser->assignRole('hr');

    $managerUser = User::factory()->create(['name' => 'Manager A', 'email' => 'manager.a@example.com']);
    $managerUser->assignRole('manager');

    $otherManagerUser = User::factory()->create(['name' => 'Manager B', 'email' => 'manager.b@example.com']);
    $otherManagerUser->assignRole('manager');

    $teamA = Team::create([
        'name' => 'Team Alpha',
        'icon' => 'team-icons/activity.png',
        'expected_size' => 5,
        'department' => Department::DEVELOPMENT,
        'status' => TeamStatus::ACTIVE,
        'team_lead_id' => $managerUser->id,
        'responsibilities' => ['Build backend services'],
    ]);

    $teamB = Team::create([
        'name' => 'Team Beta',
        'icon' => 'team-icons/activity.png',
        'expected_size' => 5,
        'department' => Department::DEVELOPMENT,
        'status' => TeamStatus::ACTIVE,
        'team_lead_id' => $otherManagerUser->id,
        'responsibilities' => ['Manage financial reports'],
    ]);

    $empUserA = User::factory()->create(['name' => 'Alice Alpha', 'email' => 'alice@example.com']);
    $empUserA->assignRole('employee');
    $empProfileA = EmployeeProfile::create([
        'user_id' => $empUserA->id,
        'code' => 'EMP-A01',
        'identity_number' => '1111111111111111',
        'phone' => '0811111111',
        'date_of_birth' => '1995-01-01',
        'gender' => 'female',
        'place_of_birth' => 'Jakarta',
        'address' => 'Jakarta',
        'city' => 'Jakarta',
        'postal_code' => '10110',
    ]);
    JobInformation::create([
        'employee_id' => $empProfileA->id,
        'job_title' => 'Backend Engineer',
        'team_id' => $teamA->id,
        'years_experience' => 3,
        'status' => 'active',
        'employment_type' => 'full_time',
        'work_location' => 'office',
        'start_date' => '2024-01-01',
        'monthly_salary' => 10000000,
        'skill_level' => 'mid',
    ]);
    TeamMember::create([
        'team_id' => $teamA->id,
        'employee_id' => $empProfileA->id,
        'joined_at' => now(),
    ]);

    $empUserB = User::factory()->create(['name' => 'Bob Beta', 'email' => 'bob@example.com']);
    $empUserB->assignRole('employee');
    $empProfileB = EmployeeProfile::create([
        'user_id' => $empUserB->id,
        'code' => 'EMP-B01',
        'identity_number' => '2222222222222222',
        'phone' => '0822222222',
        'date_of_birth' => '1995-02-02',
        'gender' => 'male',
        'place_of_birth' => 'Bandung',
        'address' => 'Bandung',
        'city' => 'Bandung',
        'postal_code' => '40111',
    ]);
    JobInformation::create([
        'employee_id' => $empProfileB->id,
        'job_title' => 'Accountant',
        'team_id' => $teamB->id,
        'years_experience' => 2,
        'status' => 'active',
        'employment_type' => 'full_time',
        'work_location' => 'office',
        'start_date' => '2024-01-01',
        'monthly_salary' => 9000000,
        'skill_level' => 'mid',
    ]);
    TeamMember::create([
        'team_id' => $teamB->id,
        'employee_id' => $empProfileB->id,
        'joined_at' => now(),
    ]);

    Attendance::create([
        'employee_id' => $empProfileA->id,
        'date' => now()->toDateString(),
        'check_in' => now()->setTime(8, 30),
        'status' => 'present',
    ]);

    Attendance::create([
        'employee_id' => $empProfileB->id,
        'date' => now()->toDateString(),
        'check_in' => now()->setTime(9, 15),
        'status' => 'late',
    ]);

    // 1. Manager A only sees Alice Alpha (Team Alpha), NOT Bob Beta (Team Beta)
    Sanctum::actingAs($managerUser);
    $managerRes = $this->getJson('/api/v1/reports/attendance');
    $managerRes->assertStatus(200)
        ->assertJsonPath('success', true);

    $managerSummary = $managerRes->json('data.employees_summary');
    expect($managerSummary)->toHaveCount(1);
    expect($managerSummary[0]['name'])->toBe('Alice Alpha');

    // 2. HR sees both Alice Alpha and Bob Beta
    Sanctum::actingAs($hrUser);
    $hrRes = $this->getJson('/api/v1/reports/attendance');
    $hrRes->assertStatus(200);
    expect($hrRes->json('data.employees_summary'))->toHaveCount(2);

    // 3. Employee is forbidden from accessing reports
    Sanctum::actingAs($empUserA);
    $empRes = $this->getJson('/api/v1/reports/attendance');
    $empRes->assertStatus(403);
});

test('settings endpoints enforce role permissions for company vs personal settings', function () {
    $hrUser = User::factory()->create([
        'password' => Hash::make('oldpassword123'),
    ]);
    $hrUser->assignRole('hr');

    $employeeUser = User::factory()->create([
        'password' => Hash::make('empsecret123'),
    ]);
    $employeeUser->assignRole('employee');

    // HR can read and update company settings
    Sanctum::actingAs($hrUser);
    $this->getJson('/api/v1/settings')
        ->assertStatus(200)
        ->assertJsonStructure(['data' => ['company', 'notifications']]);

    $this->putJson('/api/v1/settings/company', [
        'name' => 'HIP HRIS Corp',
        'email' => 'contact@herointiputra.com',
        'phone' => '021-555999',
        'address' => 'Jl. Sudirman Jakarta',
        'timezone' => 'Asia/Jakarta',
        'work_days_per_week' => 5,
    ])->assertStatus(200)
        ->assertJsonPath('data.name', 'HIP HRIS Corp');

    // Employee can read settings, update own notification preferences, and change own password,
    // but CANNOT update company settings (403)
    Sanctum::actingAs($employeeUser);
    $this->getJson('/api/v1/settings')->assertStatus(200);

    $this->putJson('/api/v1/settings/company', [
        'name' => 'Hacked Corp',
        'email' => 'hack@example.com',
        'timezone' => 'Asia/Jakarta',
        'work_days_per_week' => 5,
    ])->assertStatus(403);

    $this->putJson('/api/v1/settings/notifications', [
        'email_leave_request' => false,
        'push_task_assigned' => true,
    ])->assertStatus(200)
        ->assertJsonPath('data.email_leave_request', false);

    $this->postJson('/api/v1/settings/change-password', [
        'current_password' => 'empsecret123',
        'new_password' => 'newsecret456',
        'new_password_confirmation' => 'newsecret456',
    ])->assertStatus(200);
});

test('database notifications endpoints return unread count and mark notifications as read', function () {
    $user = User::factory()->create();
    $user->assignRole('employee');

    $profile = EmployeeProfile::create([
        'user_id' => $user->id,
        'code' => 'EMP-N01',
        'identity_number' => '3333333333333333',
        'phone' => '0833333333',
        'date_of_birth' => '1996-03-03',
        'gender' => 'male',
        'place_of_birth' => 'Surabaya',
        'address' => 'Surabaya',
        'city' => 'Surabaya',
        'postal_code' => '60111',
    ]);

    $leave = LeaveRequest::create([
        'employee_id' => $profile->id,
        'leave_type' => 'annual_leave',
        'start_date' => now()->addDays(2)->toDateString(),
        'end_date' => now()->addDays(4)->toDateString(),
        'total_days' => 3,
        'reason' => 'Liburan keluarga',
        'status' => 'pending',
    ]);

    $user->notify(new LeaveRequestCreated($leave));

    Sanctum::actingAs($user);

    // 1. Check unread count = 1
    $this->getJson('/api/v1/notifications/unread-count')
        ->assertStatus(200)
        ->assertJsonPath('data.unread_count', 1);

    // 2. Fetch paginated notifications
    $listRes = $this->getJson('/api/v1/notifications');
    $listRes->assertStatus(200)
        ->assertJsonCount(1, 'data.data');

    $notifId = $listRes->json('data.data.0.id');

    // 3. Mark single notification as read
    $this->patchJson("/api/v1/notifications/{$notifId}/read")
        ->assertStatus(200)
        ->assertJsonPath('data.is_read', true);

    $this->getJson('/api/v1/notifications/unread-count')
        ->assertStatus(200)
        ->assertJsonPath('data.unread_count', 0);

    // 4. Send another notification and mark all as read
    $user->notify(new LeaveRequestCreated($leave));
    $this->getJson('/api/v1/notifications/unread-count')
        ->assertJsonPath('data.unread_count', 1);

    $this->patchJson('/api/v1/notifications/read-all')
        ->assertStatus(200);

    $this->getJson('/api/v1/notifications/unread-count')
        ->assertJsonPath('data.unread_count', 0);
});

<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\FinanceSeeder;
use Database\Seeders\HrSeeder;
use Database\Seeders\ManagerSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SecuritySeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SuperAdminRoleAndSeederTest extends TestCase
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

    private function createUserWithRole(string $role, ?string $email = null): User
    {
        $user = User::factory()->create([
            'email' => $email ?? ($role.'-'.uniqid().'@example.com'),
            'password' => bcrypt('Secret123!'),
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    private function createEmployeeProfileForUser(User $user, string $code): EmployeeProfile
    {
        $profile = $user->employeeProfile()->create([
            'code' => $code,
            'identity_number' => '3171'.rand(100000000000, 999999999999),
            'phone' => '0812'.rand(10000000, 99999999),
            'date_of_birth' => '1995-01-01',
            'gender' => 'male',
            'place_of_birth' => 'Jakarta',
            'address' => 'Jl. Sudirman No. 1',
            'city' => 'Jakarta',
            'postal_code' => '10220',
        ]);

        $profile->jobInformation()->create([
            'job_title' => 'Engineer',
            'years_experience' => 3,
            'status' => 'active',
            'employment_type' => 'full_time',
            'work_location' => 'office',
            'start_date' => '2024-01-01',
            'monthly_salary' => 10000000,
            'skill_level' => 'expert',
        ]);

        $profile->bankInformation()->create([
            'bank_name' => 'bca',
            'account_number' => '1234567890',
            'account_holder_name' => $user->name,
            'account_type' => 'saving',
        ]);

        $profile->emergencyContacts()->create([
            'full_name' => 'Emergency Contact',
            'phone' => '081200000000',
            'relationship' => 'Family',
            'email' => 'emergency@example.com',
        ]);

        return $profile;
    }

    public function test_super_admin_passes_all_permission_middleware_via_gate_before_even_without_explicit_permissions(): void
    {
        // Strip all explicit permissions from super-admin role to prove Gate::before grants access
        $superAdminRole = Role::where('name', 'super-admin')->where('guard_name', 'sanctum')->firstOrFail();
        $superAdminRole->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdmin = $this->createUserWithRole('super-admin');

        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/roles')->assertStatus(200);
        $this->getJson('/api/v1/permissions')->assertStatus(200);
        $this->getJson('/api/v1/dashboard/statistics')->assertStatus(200);
        $this->getJson('/api/v1/dashboard/security-statistics')->assertStatus(200);
        $this->getJson('/api/v1/employees')->assertStatus(200);
        $this->getJson('/api/v1/my-profile')->assertStatus(200)->assertJsonPath('data.user.email', $superAdmin->email);
    }

    public function test_admin_is_rejected_with_403_on_rbac_endpoints(): void
    {
        $admin = $this->createUserWithRole('admin');
        $employeeRole = Role::where('name', 'employee')->where('guard_name', 'sanctum')->firstOrFail();

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/roles')->assertStatus(403);
        $this->getJson('/api/v1/permissions')->assertStatus(403);
        $this->postJson("/api/v1/roles/{$employeeRole->id}/assign-permissions", [
            'permissions' => ['dashboard-view'],
        ])->assertStatus(403);
    }

    public function test_super_admin_role_cannot_be_renamed_deleted_or_have_permissions_changed(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $superAdminRole = Role::where('name', 'super-admin')->where('guard_name', 'sanctum')->firstOrFail();

        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/v1/roles/{$superAdminRole->id}", [
            'name' => 'renamed-super-admin',
        ])->assertStatus(403);

        $this->deleteJson("/api/v1/roles/{$superAdminRole->id}")
            ->assertStatus(403);

        $this->postJson("/api/v1/roles/{$superAdminRole->id}/assign-permissions", [
            'permissions' => ['dashboard-view'],
        ])->assertStatus(403);
    }

    public function test_super_admin_user_cannot_be_deleted_or_downgraded_and_last_super_admin_is_protected(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $superAdminProfile = $this->createEmployeeProfileForUser($superAdmin, 'SA001');

        Sanctum::actingAs($superAdmin);

        // Cannot delete super-admin employee via API
        $this->deleteJson("/api/v1/employees/{$superAdminProfile->id}")
            ->assertStatus(403);

        // Cannot downgrade super-admin role via API
        $this->putJson("/api/v1/employees/{$superAdminProfile->id}", [
            'roles' => ['admin'],
        ])->assertStatus(403);

        // Model-level safeguard prevents deleting the last super-admin
        $this->expectException(HttpException::class);
        $superAdmin->delete();
    }

    public function test_only_super_admin_can_assign_super_admin_role(): void
    {
        $admin = $this->createUserWithRole('admin');
        $targetUser = $this->createUserWithRole('employee');
        $targetProfile = $this->createEmployeeProfileForUser($targetUser, 'EMP999');

        // Admin cannot promote user to super-admin
        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/employees/{$targetProfile->id}", [
            'roles' => ['super-admin'],
        ])->assertStatus(403);

        // Super Admin CAN promote user to super-admin
        $superAdmin = $this->createUserWithRole('super-admin');
        Sanctum::actingAs($superAdmin);
        $this->putJson("/api/v1/employees/{$targetProfile->id}", [
            'roles' => ['super-admin'],
        ])->assertStatus(200);

        $this->assertTrue($targetUser->fresh()->hasRole('super-admin'));
    }

    public function test_admin_cannot_reset_mfa_of_admin_or_super_admin_but_can_reset_employee_and_super_admin_can_reset_all(): void
    {
        $superAdmin = $this->createUserWithRole('super-admin');
        $admin = $this->createUserWithRole('admin');
        $otherAdmin = $this->createUserWithRole('admin');
        $employee = $this->createUserWithRole('employee');

        // Enable dummy MFA state on targets
        foreach ([$superAdmin, $otherAdmin, $employee] as $target) {
            $target->forceFill([
                'mfa_secret' => 'JBSWY3DPEHPK3PXP',
                'mfa_enabled_at' => now(),
                'mfa_recovery_codes' => ['hashed-code'],
            ])->save();
        }

        // Admin is forbidden from resetting MFA of super-admin or another admin
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/users/{$superAdmin->id}/mfa/reset")->assertStatus(403);
        $this->postJson("/api/v1/users/{$otherAdmin->id}/mfa/reset")->assertStatus(403);

        // Admin CAN reset MFA of employee
        $this->postJson("/api/v1/users/{$employee->id}/mfa/reset")->assertStatus(200);
        $this->assertFalse($employee->fresh()->hasMfaEnabled());

        // Super Admin CAN reset MFA of admin
        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/v1/users/{$otherAdmin->id}/mfa/reset")->assertStatus(200);
        $this->assertFalse($otherAdmin->fresh()->hasMfaEnabled());
    }

    public function test_seeders_are_idempotent_when_run_twice(): void
    {
        $seeders = [
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            SuperAdminSeeder::class,
            AdminSeeder::class,
            ManagerSeeder::class,
            EmployeeSeeder::class,
            HrSeeder::class,
            FinanceSeeder::class,
            SecuritySeeder::class,
        ];

        // First run
        foreach ($seeders as $seeder) {
            $this->seed($seeder);
        }

        $firstRunUserCount = User::count();
        $firstRunProfileCount = EmployeeProfile::count();

        // Second run (must not error or duplicate)
        foreach ($seeders as $seeder) {
            $this->seed($seeder);
        }

        $this->assertSame($firstRunUserCount, User::count());
        $this->assertSame($firstRunProfileCount, EmployeeProfile::count());

        $superAdmin = User::where('email', 'superadmin@gmail.com')->first();
        $this->assertNotNull($superAdmin);
        $this->assertTrue($superAdmin->hasRole('super-admin'));

        $admin = User::where('email', 'admin@gmail.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertNotNull($admin->employeeProfile);
        $this->assertNotNull($admin->employeeProfile->jobInformation);
        $this->assertNotNull($admin->employeeProfile->bankInformation);
        $this->assertTrue($admin->employeeProfile->emergencyContacts()->exists());
    }

    public function test_user_seeders_fail_clearly_when_seeder_default_password_is_empty(): void
    {
        $originalEnv = $_ENV['SEEDER_DEFAULT_PASSWORD'] ?? null;
        $originalServer = $_SERVER['SEEDER_DEFAULT_PASSWORD'] ?? null;
        $originalGetenv = getenv('SEEDER_DEFAULT_PASSWORD');

        putenv('SEEDER_DEFAULT_PASSWORD=');
        $_ENV['SEEDER_DEFAULT_PASSWORD'] = '';
        $_SERVER['SEEDER_DEFAULT_PASSWORD'] = '';

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('SEEDER_DEFAULT_PASSWORD');
            $this->seed(SuperAdminSeeder::class);
        } finally {
            if ($originalGetenv !== false) {
                putenv("SEEDER_DEFAULT_PASSWORD={$originalGetenv}");
            }
            if ($originalEnv !== null) {
                $_ENV['SEEDER_DEFAULT_PASSWORD'] = $originalEnv;
            }
            if ($originalServer !== null) {
                $_SERVER['SEEDER_DEFAULT_PASSWORD'] = $originalServer;
            }
        }
    }
}

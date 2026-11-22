<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EmployeeCodeGenerationTest extends TestCase
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

    private function employeePayload(string $email, string $accountNumber): array
    {
        return [
            'name' => 'Test Employee',
            'email' => $email,
            'password' => 'Secret123!',
            'identity_number' => '3171'.rand(100000000000, 999999999999),
            'phone' => '0812'.rand(10000000, 99999999),
            'date_of_birth' => '1995-05-05',
            'hobby' => 'Reading',
            'place_of_birth' => 'Jakarta',
            'gender' => 'male',
            'address' => 'Jl. Sudirman No. 1',
            'city' => 'Jakarta',
            'postal_code' => '10110',
            'job_title' => 'Sales Associate',
            'department' => 'sales',
            'years_experience' => 2,
            'status' => 'active',
            'employment_type' => 'full_time',
            'work_location' => 'store',
            'start_date' => now()->toDateString(),
            'monthly_salary' => 6000000,
            'skill_level' => 'intermediate',
            'bank_name' => 'bca',
            'bank_branch' => 'Sudirman',
            'account_number' => $accountNumber,
            'account_holder_name' => 'Test Employee',
            'account_type' => 'savings',
            'preferred_language' => 'english',
            'additional_notes' => 'Test',
            'roles' => ['employee'],
            'emergency_contacts' => [
                [
                    'full_name' => 'Emergency Contact',
                    'relationship' => 'sibling',
                    'phone' => '081312345678',
                ],
            ],
            'educations' => [
                [
                    'level' => 's1',
                    'institution' => 'Universitas Indonesia',
                    'major' => 'Management',
                    'start_year' => 2016,
                    'end_year' => 2020,
                    'gpa' => 3.5,
                    'is_highest' => true,
                ],
            ],
        ];
    }

    public function test_create_employee_after_soft_deleted_employee_in_same_month_does_not_collide_code(): void
    {
        $hr = User::factory()->create(['email' => 'hr-test@example.com']);
        $hr->syncRoles(['hr']);
        Sanctum::actingAs($hr);

        // Create first employee -> gets EMP{YYYY}{MM}0001
        $res1 = $this->postJson('/api/v1/employees', $this->employeePayload('emp1@example.com', '1111222233'))
            ->assertStatus(201);
        $code1 = $res1->json('data.code');
        $emp1Id = $res1->json('data.id');

        // Soft-delete first employee
        $this->deleteJson("/api/v1/employees/{$emp1Id}")->assertStatus(200);
        $this->assertSoftDeleted('employee_profiles', ['id' => $emp1Id]);

        // Create second employee in the same month -> MUST get EMP{YYYY}{MM}0002 (no duplicate key error!)
        $res2 = $this->postJson('/api/v1/employees', $this->employeePayload('emp2@example.com', '4444555566'))
            ->assertStatus(201);
        $code2 = $res2->json('data.code');

        $this->assertNotSame($code1, $code2);
        $this->assertSame((int) substr($code1, -4) + 1, (int) substr($code2, -4));
    }

    public function test_failed_employee_creation_rolls_back_user_without_leaving_orphan(): void
    {
        $hr = User::factory()->create(['email' => 'hr-rollback@example.com']);
        $hr->syncRoles(['hr']);
        Sanctum::actingAs($hr);

        // First employee succeeds with account_number '7777888899'
        $this->postJson('/api/v1/employees', $this->employeePayload('emp-ok@example.com', '7777888899'))
            ->assertStatus(201);

        // Second employee attempts duplicate bank account_number -> fails at BankInformation insert
        $this->postJson('/api/v1/employees', $this->employeePayload('emp-orphan-check@example.com', '7777888899'))
            ->assertStatus(500);

        // User MUST NOT remain as orphan in users table
        $this->assertDatabaseMissing('users', ['email' => 'emp-orphan-check@example.com']);
        $this->assertSame(1, EmployeeProfile::count());
    }
}

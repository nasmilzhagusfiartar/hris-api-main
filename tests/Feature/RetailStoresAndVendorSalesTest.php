<?php

namespace Tests\Feature;

use App\Interfaces\PayrollRepositoryInterface;
use App\Models\Attendance;
use App\Models\EmployeeProfile;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RetailStoresAndVendorSalesTest extends TestCase
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

    private function createUserWithProfile(
        string $role,
        string $employmentType = 'full_time',
        ?int $storeId = null,
        ?string $vendorName = null,
        ?string $department = 'sales'
    ): User {
        $user = User::factory()->create([
            'email' => $role.'-'.uniqid().'@example.com',
            'password' => bcrypt('Secret123!'),
        ]);
        $user->syncRoles([$role]);

        $profile = $user->employeeProfile()->create([
            'code' => 'EMP'.rand(1000, 9999),
            'identity_number' => '3171'.rand(100000000000, 999999999999),
            'phone' => '0812'.rand(10000000, 99999999),
            'date_of_birth' => '1996-01-01',
            'gender' => 'female',
            'place_of_birth' => 'Jakarta',
            'address' => 'Jl. Sudirman No. 1',
            'city' => 'Jakarta',
            'postal_code' => '10220',
        ]);

        $profile->jobInformation()->create([
            'job_title' => $employmentType === 'vendor' ? 'Brand SPG' : 'Sales Associate',
            'department' => $department,
            'store_id' => $storeId,
            'years_experience' => 2,
            'status' => 'active',
            'employment_type' => $employmentType,
            'vendor_name' => $vendorName,
            'work_location' => 'store',
            'start_date' => '2025-01-01',
            'monthly_salary' => $employmentType === 'vendor' ? 0 : 6000000,
            'skill_level' => 'intermediate',
        ]);

        return $user->load('employeeProfile.jobInformation.store');
    }

    public function test_stores_crud_permissions_validation_and_active_employee_delete_protection(): void
    {
        $hr = $this->createUserWithProfile('hr');
        $employee = $this->createUserWithProfile('employee');

        // Employee is forbidden from managing stores
        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/stores')->assertStatus(403);
        $this->postJson('/api/v1/stores', [
            'name' => 'Toys City Grand Indonesia',
            'brand' => 'Toys City',
            'city' => 'Jakarta',
        ])->assertStatus(403);

        // HR can create store with valid brand (Toys City / Intex Indonesia)
        Sanctum::actingAs($hr);
        $this->postJson('/api/v1/stores', [
            'name' => 'Invalid Brand Store',
            'brand' => 'Unknown Brand',
            'city' => 'Jakarta',
        ])->assertStatus(422);

        $createRes = $this->postJson('/api/v1/stores', [
            'code' => 'TC-GI-01',
            'name' => 'Toys City Grand Indonesia',
            'brand' => 'Toys City',
            'city' => 'Jakarta',
            'address' => 'Jl. MH Thamrin No. 1',
            'is_active' => true,
        ])->assertStatus(201);

        $storeId = $createRes->json('data.id');

        // Updating store (including string boolean 'false' / empty code normalization) succeeds
        $this->putJson("/api/v1/stores/{$storeId}", [
            'code' => 'TC-GI-01',
            'name' => 'Toys City Grand Indonesia Mall',
            'brand' => 'Toys City',
            'city' => 'Jakarta Pusat',
            'address' => '',
            'is_active' => 'false',
        ])->assertStatus(200)
            ->assertJsonPath('data.name', 'Toys City Grand Indonesia Mall')
            ->assertJsonPath('data.is_active', false);

        // Creating store with string boolean 'true' and empty string code succeeds
        $mpStyleRes = $this->post('/api/v1/stores', [
            'code' => '',
            'name' => 'Toys City Senayan',
            'brand' => 'Toys City',
            'city' => 'Jakarta',
            'address' => '',
            'is_active' => 'true',
        ], ['Accept' => 'application/json'])->assertStatus(201);
        $this->assertTrue((bool) $mpStyleRes->json('data.is_active'));
        $this->deleteJson('/api/v1/stores/'.$mpStyleRes->json('data.id'))->assertStatus(200);

        // Assign an active employee to the store
        $this->createUserWithProfile('employee', 'vendor', $storeId, 'Mattel Indonesia');

        // Deleting store with active employee MUST be rejected (422)
        $this->deleteJson("/api/v1/stores/{$storeId}")->assertStatus(422);

        // Create a second store without employees -> soft delete succeeds
        $emptyStoreRes = $this->postJson('/api/v1/stores', [
            'code' => 'IX-BDG-01',
            'name' => 'Intex Indonesia Paris Van Java',
            'brand' => 'Intex Indonesia',
            'city' => 'Bandung',
        ])->assertStatus(201);

        $emptyStoreId = $emptyStoreRes->json('data.id');
        $this->deleteJson("/api/v1/stores/{$emptyStoreId}")->assertStatus(200);
        $this->assertSoftDeleted('stores', ['id' => $emptyStoreId]);
    }

    public function test_vendor_employee_is_excluded_from_payroll_and_leave_but_can_clock_in(): void
    {
        $store = Store::create([
            'code' => 'TC-PIM-01',
            'name' => 'Toys City Pondok Indah Mall',
            'brand' => 'Toys City',
            'city' => 'Jakarta',
            'is_active' => true,
        ]);

        $regularUser = $this->createUserWithProfile('employee', 'full_time', $store->id, null, 'sales');
        $vendorUser = $this->createUserWithProfile('employee', 'vendor', $store->id, 'Hasbro Indonesia', 'sales');

        // Both employees have attendance in current month
        foreach ([$regularUser, $vendorUser] as $u) {
            Attendance::create([
                'employee_id' => $u->employeeProfile->id,
                'date' => now()->startOfMonth()->addDay()->toDateString(),
                'check_in' => now()->startOfMonth()->addDay()->setTime(9, 0),
                'check_out' => now()->startOfMonth()->addDay()->setTime(17, 0),
                'status' => 'present',
            ]);
        }

        // Vendor CANNOT submit leave requests or view my-leave-requests (403)
        Sanctum::actingAs($vendorUser);
        $this->getJson('/api/v1/my-leave-requests')->assertStatus(403);
        $this->postJson('/api/v1/leave-requests', [
            'employee_id' => $vendorUser->employeeProfile->id,
            'leave_type' => 'annual_leave',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'reason' => 'Vacation',
        ])->assertStatus(403);

        // Vendor CAN still clock in
        $this->postJson('/api/v1/attendances/check-in', [
            'check_in_lat' => -6.2,
            'check_in_long' => 106.8,
        ])->assertStatus(201);

        // Payroll generation MUST exclude vendor employee and only include regular employee
        $payrollRepo = app(PayrollRepositoryInterface::class);
        $payroll = $payrollRepo->generatePayroll(now()->format('Y-m'));
        $payrollEmployeeIds = $payroll->payrollDetails()->pluck('employee_id')->all();

        $this->assertContains($regularUser->employeeProfile->id, $payrollEmployeeIds);
        $this->assertNotContains($vendorUser->employeeProfile->id, $payrollEmployeeIds);
    }

    public function test_employees_and_reports_filter_by_store_department_employment_type_and_vendor(): void
    {
        $hr = $this->createUserWithProfile('hr', 'full_time', null, null, 'human_resources');
        $storeA = Store::create([
            'code' => 'TC-JKT-01',
            'name' => 'Toys City Senayan City',
            'brand' => 'Toys City',
            'city' => 'Jakarta',
            'is_active' => true,
        ]);
        $storeB = Store::create([
            'code' => 'IX-SBY-01',
            'name' => 'Intex Indonesia Tunjungan Plaza',
            'brand' => 'Intex Indonesia',
            'city' => 'Surabaya',
            'is_active' => true,
        ]);

        $vendorA = $this->createUserWithProfile('employee', 'vendor', $storeA->id, 'Lego Indonesia', 'sales');
        $regularB = $this->createUserWithProfile('employee', 'full_time', $storeB->id, null, 'store_operations');

        Sanctum::actingAs($hr);

        // Filter employees by store_id
        $resStore = $this->getJson("/api/v1/employees?store_id={$storeA->id}")->assertStatus(200);
        $this->assertCount(1, $resStore->json('data'));
        $this->assertSame($vendorA->employeeProfile->id, $resStore->json('data.0.id'));

        // Filter employees by employment_type=vendor & vendor_name
        $resVendor = $this->getJson('/api/v1/employees?type=vendor&vendor_name=Lego')->assertStatus(200);
        $this->assertCount(1, $resVendor->json('data'));
        $this->assertSame('Lego Indonesia', $resVendor->json('data.0.job_information.vendor_name'));

        // Filter attendance report by employment_type=vendor & store_id
        $this->getJson("/api/v1/reports/attendance?employment_type=vendor&store_id={$storeA->id}")
            ->assertStatus(200);

        // Filter employee report by department=store_operations
        $resReport = $this->getJson('/api/v1/reports/employees?department=store_operations')
            ->assertStatus(200);
        $this->assertSame(1, $resReport->json('data.total_employees'));
        $this->assertNotNull($regularB);
    }
}

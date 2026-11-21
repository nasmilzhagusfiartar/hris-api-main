<?php

namespace Tests\Feature;

use App\Interfaces\PayrollRepositoryInterface;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PayrollPeriodAndDeductionTest extends TestCase
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

    private function createEmployee(
        string $workLocation = 'office',
        float $salary = 6000000,
        string $startDate = '2025-01-01',
        string $employmentType = 'full_time'
    ): User {
        $user = User::factory()->create([
            'email' => 'emp-'.uniqid().'@example.com',
        ]);
        $user->syncRoles(['employee']);

        $profile = $user->employeeProfile()->create([
            'code' => 'EMP'.rand(100000, 999999),
            'identity_number' => '3171'.rand(100000000000, 999999999999),
            'phone' => '0812'.rand(10000000, 99999999),
            'date_of_birth' => '1996-01-01',
            'gender' => 'male',
            'place_of_birth' => 'Jakarta',
            'address' => 'Jl. Sudirman No. 1',
            'city' => 'Jakarta',
            'postal_code' => '10220',
        ]);

        $profile->jobInformation()->create([
            'job_title' => $workLocation === 'store' ? 'Store Staff' : 'Office Staff',
            'department' => 'sales',
            'years_experience' => 2,
            'status' => 'active',
            'employment_type' => $employmentType,
            'vendor_name' => $employmentType === 'vendor' ? 'Mattel Indonesia' : null,
            'work_location' => $workLocation,
            'start_date' => $startDate,
            'monthly_salary' => $salary,
            'skill_level' => 'intermediate',
        ]);

        return $user->load('employeeProfile.jobInformation');
    }

    public function test_payroll_period_is_16th_to_15th_including_cross_year_and_pay_date_is_28th(): void
    {
        Carbon::setTestNow(Carbon::create(2027, 1, 20, 10, 0, 0));
        $this->createEmployee('office', 5000000);

        $repo = app(PayrollRepositoryInterface::class);

        // Normal month: Nov 2026 => 16 Oct 2026 - 15 Nov 2026, pay_date 28 Nov 2026
        $nov = $repo->generatePayroll('2026-11');
        $this->assertSame('2026-10-16', $nov->period_start->format('Y-m-d'));
        $this->assertSame('2026-11-15', $nov->period_end->format('Y-m-d'));
        $this->assertSame('2026-11-28', $nov->pay_date->format('Y-m-d'));

        // Cross-year: Jan 2027 => 16 Dec 2026 - 15 Jan 2027, pay_date 28 Jan 2027
        $jan = $repo->generatePayroll('2027-01');
        $this->assertSame('2026-12-16', $jan->period_start->format('Y-m-d'));
        $this->assertSame('2027-01-15', $jan->period_end->format('Y-m-d'));
        $this->assertSame('2027-01-28', $jan->pay_date->format('Y-m-d'));
    }

    public function test_attendance_on_16th_to_31st_falls_into_next_month_payroll_period(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 10, 0, 0));
        $emp = $this->createEmployee('office', 5000000);

        // Attendance on 20 Oct 2026 belongs to November 2026 payroll (16 Oct - 15 Nov), NOT October 2026 (16 Sep - 15 Oct)
        Attendance::create([
            'employee_id' => $emp->employeeProfile->id,
            'date' => '2026-10-20',
            'check_in' => '2026-10-20 08:55:00',
            'check_out' => '2026-10-20 17:05:00',
            'status' => 'present',
        ]);

        $repo = app(PayrollRepositoryInterface::class);
        $octPayroll = $repo->generatePayroll('2026-10');
        $novPayroll = $repo->generatePayroll('2026-11');

        $octDetail = $octPayroll->payrollDetails->firstWhere('employee_id', $emp->employeeProfile->id);
        $novDetail = $novPayroll->payrollDetails->firstWhere('employee_id', $emp->employeeProfile->id);

        $this->assertSame(0, (int) $octDetail->attended_days);
        $this->assertSame(1, (int) $novDetail->attended_days);
    }

    public function test_missing_attendance_is_deducted_while_sick_permission_approved_leave_and_late_are_not(): void
    {
        // Freeze time AFTER period end (15 Nov 2026)
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 10, 0, 0));

        // Period: 16 Oct 2026 (Fri) .. 15 Nov 2026 (Sun) -> Office Mon-Fri has 21 working days
        $emp = $this->createEmployee('office', 6300000, '2025-01-01');
        $empId = $emp->employeeProfile->id;

        // Collect all Mon-Fri working days in 2026-10-16..2026-11-15
        $workDates = [];
        $c = Carbon::parse('2026-10-16');
        while ($c->lte(Carbon::parse('2026-11-15'))) {
            if ($c->dayOfWeekIso <= 5) {
                $workDates[] = $c->format('Y-m-d');
            }
            $c->addDay();
        }
        $totalWorkingDays = count($workDates); // 21

        // Day 0: present, Day 1: late (not deducted), Day 2: sick (not deducted), Day 3: permission (not deducted)
        // Day 4-5: approved leave (2 days, not deducted)
        // Days 6..18: present (13 days)
        // Days 19..20: NO attendance & NO leave -> 2 absent days!
        Attendance::create(['employee_id' => $empId, 'date' => $workDates[0], 'status' => 'present']);
        Attendance::create(['employee_id' => $empId, 'date' => $workDates[1], 'status' => 'late']);
        Attendance::create(['employee_id' => $empId, 'date' => $workDates[2], 'status' => 'sick']);
        Attendance::create(['employee_id' => $empId, 'date' => $workDates[3], 'status' => 'permission']);

        LeaveRequest::create([
            'employee_id' => $empId,
            'leave_type' => 'annual_leave',
            'start_date' => $workDates[4],
            'end_date' => $workDates[5],
            'total_days' => 2,
            'reason' => 'Family trip',
            'status' => 'approved',
        ]);

        for ($i = 6; $i <= $totalWorkingDays - 3; $i++) {
            Attendance::create(['employee_id' => $empId, 'date' => $workDates[$i], 'status' => 'present']);
        }

        $repo = app(PayrollRepositoryInterface::class);
        $payroll = $repo->generatePayroll('2026-11');
        $detail = $payroll->payrollDetails->firstWhere('employee_id', $empId);

        $expectedDeduction = round((6300000 / $totalWorkingDays) * 2); // 600000

        $this->assertSame($totalWorkingDays, (int) $detail->working_days);
        $this->assertSame(1, (int) $detail->late_days);
        $this->assertSame(1, (int) $detail->sick_days);
        $this->assertSame(1, (int) $detail->permission_days);
        $this->assertSame(2, (int) $detail->leave_days);
        $this->assertSame(2, (int) $detail->absent_days);
        $this->assertEquals($expectedDeduction, (float) $detail->deduction_amount);
        $this->assertEquals(6300000 - $expectedDeduction, (float) $detail->final_salary);
    }

    public function test_prorata_from_start_date_work_location_days_future_days_vendor_exclusion_and_paid_protection(): void
    {
        // Freeze time on 2026-11-10 (BEFORE period_end 2026-11-15 -> future working days 11..15 Nov NOT absent!)
        Carbon::setTestNow(Carbon::create(2026, 11, 10, 10, 0, 0));

        // 1. Mid-period joiner (office, Mon-Fri) starting 2026-11-02
        $prorataEmp = $this->createEmployee('office', 5000000, '2026-11-02');
        // 2. Store employee (Mon-Sat -> more working days than office!)
        $storeEmp = $this->createEmployee('store', 6000000, '2025-01-01');
        // 3. Vendor employee -> MUST be excluded
        $vendorEmp = $this->createEmployee('store', 0, '2025-01-01', 'vendor');

        // Prorata employee attends every working day from 2026-11-02 to 2026-11-10 (Mon-Fri: 2,3,4,5,6,9,10 = 7 days)
        foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-09', '2026-11-10'] as $d) {
            Attendance::create(['employee_id' => $prorataEmp->employeeProfile->id, 'date' => $d, 'status' => 'present']);
        }

        $repo = app(PayrollRepositoryInterface::class);
        $payroll = $repo->generatePayroll('2026-11');

        $prorataDetail = $payroll->payrollDetails->firstWhere('employee_id', $prorataEmp->employeeProfile->id);
        $storeDetail = $payroll->payrollDetails->firstWhere('employee_id', $storeEmp->employeeProfile->id);
        $vendorDetail = $payroll->payrollDetails->firstWhere('employee_id', $vendorEmp->employeeProfile->id);

        // Vendor excluded
        $this->assertNull($vendorDetail);

        // Prorata: 2..15 Nov Mon-Fri = 10 working days; attended 7 (2..10 Nov), future days (11..13 Nov) NOT absent => 0 absent!
        $this->assertSame(10, (int) $prorataDetail->working_days);
        $this->assertSame(0, (int) $prorataDetail->absent_days);
        $this->assertEquals(5000000.0, (float) $prorataDetail->final_salary);

        // Store (Mon-Sat) has MORE working days than Office (Mon-Fri) in 16 Oct..15 Nov (26 vs 21)
        $this->assertGreaterThan(21, (int) $storeDetail->working_days);

        // Mark payroll as Paid -> subsequent generate or updateDetail MUST fail (never recalculated!)
        $repo->markAsPaid((string) $payroll->id, '2026-11-28');
        $this->assertSame('paid', $payroll->fresh()->status);

        $this->expectException(\Exception::class);
        $repo->generatePayroll('2026-11');
    }
}

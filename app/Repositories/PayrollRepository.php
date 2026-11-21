<?php

namespace App\Repositories;

use App\Constants\CacheConstants;
use App\Interfaces\PayrollRepositoryInterface;
use App\Interfaces\SettingRepositoryInterface;
use App\Models\Attendance;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Services\EmailService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PayrollRepository implements PayrollRepositoryInterface
{
    protected EmailService $emailService;

    protected SettingRepositoryInterface $settingRepository;

    public function __construct(EmailService $emailService, SettingRepositoryInterface $settingRepository)
    {
        $this->emailService = $emailService;
        $this->settingRepository = $settingRepository;
    }

    public function getAll(
        ?string $search,
        ?int $limit,
        bool $execute
    ): Builder|Collection {
        $query = Payroll::with(['payrollDetails'])
            ->when($search, function ($query) use ($search) {
                $query->whereHas('payrollDetails.employee', function ($q) use ($search) {
                    $q->whereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', '%' . $search . '%');
                    })
                        ->orWhere('code', 'like', '%' . $search . '%');
                });
            })
            ->orderBy('salary_month', 'desc');

        if ($limit) {
            $query->take($limit);
        }

        if ($execute) {
            return $query->get();
        }

        return $query;
    }

    public function getAllPaginated(
        ?string $search,
        int $rowPerPage
    ): LengthAwarePaginator {
        $query = $this->getAll(
            $search,
            null,
            false
        );

        return $query->paginate($rowPerPage);
    }

    public function getById(string $id): Payroll
    {
        return Payroll::withCount('payrollDetails')
            ->findOrFail($id);
    }

    public function getPayrollDetailsPaginated(string $payrollId, int $perPage = 50): LengthAwarePaginator
    {
        // Verify payroll exists
        $payroll = Payroll::findOrFail($payrollId);

        // Get paginated details with optimized eager loading
        return PayrollDetail::with([
            'employee.user',
            'employee.jobInformation.team',
            'employee.bankInformation',
        ])
            ->where('payroll_id', $payrollId)
            ->orderBy('final_salary', 'desc') // Highest salary first
            ->paginate($perPage);
    }

    /**
     * Resolve payroll cut-off period (default: 16th of previous month to 15th of salary month)
     * and pay date (default: 28th of salary month, never shifted on weekends/holidays).
     *
     * @return array{period_start: Carbon, period_end: Carbon, pay_date: Carbon, cutoff_day: int, pay_day: int}
     */
    public function resolvePayrollPeriod(Carbon $salaryMonth, array $companySettings): array
    {
        $cutoffDay = max(1, min(28, (int) ($companySettings['payroll_cutoff_day'] ?? 15)));
        $payDay = max(1, min(31, (int) ($companySettings['payroll_pay_day'] ?? 28)));

        $monthStart = $salaryMonth->copy()->startOfMonth();
        $periodEnd = $monthStart->copy()->day(min($cutoffDay, $monthStart->daysInMonth))->endOfDay();

        $prevMonth = $monthStart->copy()->subMonthNoOverflow();
        $periodStart = $prevMonth->copy()
            ->day(min($cutoffDay, $prevMonth->daysInMonth))
            ->addDay()
            ->startOfDay();

        $payDate = $monthStart->copy()->day(min($payDay, $monthStart->daysInMonth))->startOfDay();

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'pay_date' => $payDate,
            'cutoff_day' => $cutoffDay,
            'pay_day' => $payDay,
        ];
    }

    public function generatePayroll(string $salaryMonth): Payroll
    {
        return DB::transaction(function () use ($salaryMonth) {
            $month = Carbon::parse($salaryMonth)->startOfMonth();

            $existingPayroll = Payroll::where('salary_month', $month->format('Y-m-d'))->first();

            if ($existingPayroll) {
                if ($existingPayroll->status === 'paid') {
                    throw new \Exception('Payroll for '.$month->format('F Y').' has already been paid and cannot be recalculated');
                }

                throw new \Exception('Payroll for '.$month->format('F Y').' has already been generated');
            }

            $companySettings = method_exists($this->settingRepository, 'getCompanySettings')
                ? $this->settingRepository->getCompanySettings()
                : [];

            $period = $this->resolvePayrollPeriod($month, $companySettings);
            /** @var Carbon $periodStart */
            $periodStart = $period['period_start'];
            /** @var Carbon $periodEnd */
            $periodEnd = $period['period_end'];
            /** @var Carbon $payDate */
            $payDate = $period['pay_date'];

            $workDaysByLocation = $companySettings['work_days_by_location'] ?? [
                'office' => [1, 2, 3, 4, 5],
                'hybrid' => [1, 2, 3, 4, 5],
                'remote' => [1, 2, 3, 4, 5],
                'store' => [1, 2, 3, 4, 5, 6],
            ];

            $payroll = Payroll::create([
                'salary_month' => $month->format('Y-m-d'),
                'period_start' => $periodStart->format('Y-m-d'),
                'period_end' => $periodEnd->format('Y-m-d'),
                'pay_date' => $payDate->format('Y-m-d'),
                'status' => 'processing',
            ]);

            $activeEmployees = EmployeeProfile::with(['jobInformation', 'user'])
                ->whereDoesntHave('user.roles', function ($query) {
                    $query->where('name', 'security');
                })
                ->whereHas('jobInformation', function ($query) use ($periodEnd) {
                    $query->where('status', 'active')
                        ->where('employment_type', '!=', 'vendor')
                        ->where(function ($q) use ($periodEnd) {
                            $q->whereNull('start_date')
                                ->orWhereDate('start_date', '<=', $periodEnd->format('Y-m-d'));
                        });
                })
                ->get();

            if ($activeEmployees->isEmpty()) {
                throw new \Exception('No active employees found for payroll generation');
            }

            $employeeIds = $activeEmployees->pluck('id')->toArray();

            // Load attendance records within [periodStart, periodEnd] keyed by employee_id -> Y-m-d
            $attendanceRows = Attendance::whereIn('employee_id', $employeeIds)
                ->whereBetween('date', [
                    $periodStart->format('Y-m-d H:i:s'),
                    $periodEnd->format('Y-m-d H:i:s'),
                ])
                ->get(['employee_id', 'date', 'status']);

            $attendanceByEmployee = [];
            foreach ($attendanceRows as $row) {
                $dateKey = Carbon::parse($row->date)->format('Y-m-d');
                $statusVal = $row->status instanceof \BackedEnum ? $row->status->value : (string) $row->status;
                $attendanceByEmployee[$row->employee_id][$dateKey] = strtolower($statusVal);
            }

            // Load approved leave requests overlapping [periodStart, periodEnd]
            $approvedLeaves = LeaveRequest::whereIn('employee_id', $employeeIds)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $periodEnd->format('Y-m-d'))
                ->whereDate('end_date', '>=', $periodStart->format('Y-m-d'))
                ->get(['employee_id', 'start_date', 'end_date']);

            $leaveDatesByEmployee = [];
            foreach ($approvedLeaves as $leave) {
                $cursor = Carbon::parse($leave->start_date)->startOfDay();
                $leaveEnd = Carbon::parse($leave->end_date)->startOfDay();
                if ($cursor->lt($periodStart)) {
                    $cursor = $periodStart->copy()->startOfDay();
                }
                if ($leaveEnd->gt($periodEnd)) {
                    $leaveEnd = $periodEnd->copy()->startOfDay();
                }
                while ($cursor->lte($leaveEnd)) {
                    $leaveDatesByEmployee[$leave->employee_id][$cursor->format('Y-m-d')] = true;
                    $cursor->addDay();
                }
            }

            // Working days in the future (when payroll is generated before period_end) are NOT counted as absent
            $today = now()->startOfDay();
            $evaluationEnd = $today->lt($periodEnd->copy()->startOfDay())
                ? $today
                : $periodEnd->copy()->startOfDay();

            $payrollDetails = [];

            foreach ($activeEmployees as $employee) {
                $jobInfo = $employee->jobInformation;
                $originalSalary = (float) ($jobInfo->monthly_salary ?? 0);

                $workLocation = $jobInfo->work_location instanceof \BackedEnum
                    ? $jobInfo->work_location->value
                    : strtolower((string) ($jobInfo->work_location ?? 'office'));

                $allowedDays = $workDaysByLocation[$workLocation]
                    ?? ($workLocation === 'store' ? [1, 2, 3, 4, 5, 6] : [1, 2, 3, 4, 5]);

                $employeeStart = $jobInfo->start_date
                    ? Carbon::parse($jobInfo->start_date)->startOfDay()
                    : $periodStart->copy()->startOfDay();

                $effectiveStart = $employeeStart->gt($periodStart->copy()->startOfDay())
                    ? $employeeStart
                    : $periodStart->copy()->startOfDay();

                $workingDays = 0;
                $presentDays = 0;
                $lateDays = 0;
                $sickDays = 0;
                $permissionDays = 0;
                $leaveDays = 0;
                $absentDays = 0;

                $empAttendance = $attendanceByEmployee[$employee->id] ?? [];
                $empLeaves = $leaveDatesByEmployee[$employee->id] ?? [];

                $cursor = $effectiveStart->copy();
                $periodEndDay = $periodEnd->copy()->startOfDay();

                while ($cursor->lte($periodEndDay)) {
                    if (in_array($cursor->dayOfWeekIso, $allowedDays, true)) {
                        $workingDays++;
                        $dateKey = $cursor->format('Y-m-d');
                        $attStatus = $empAttendance[$dateKey] ?? null;

                        if ($attStatus === 'present') {
                            $presentDays++;
                        } elseif ($attStatus === 'late') {
                            // Late is recorded, NOT deducted
                            $lateDays++;
                        } elseif ($attStatus === 'sick') {
                            // Sick is NOT deducted
                            $sickDays++;
                        } elseif ($attStatus === 'permission') {
                            // Permission is NOT deducted
                            $permissionDays++;
                        } elseif (isset($empLeaves[$dateKey])) {
                            // Approved leave is NOT deducted
                            $leaveDays++;
                        } elseif ($attStatus === 'absent') {
                            $absentDays++;
                        } elseif ($cursor->lte($evaluationEnd)) {
                            // Past/current working day with no attendance and no approved leave -> absent
                            $absentDays++;
                        }
                    }
                    $cursor->addDay();
                }

                // Deduction formula: round((basic_salary / working_days) * absent_days), min 0
                $deduction = $workingDays > 0
                    ? max(0.0, (float) round(($originalSalary / $workingDays) * $absentDays))
                    : 0.0;

                $finalSalary = max(0.0, round($originalSalary - $deduction));
                $attendedDays = $presentDays + $lateDays;

                $payrollDetails[] = [
                    'payroll_id' => $payroll->id,
                    'employee_id' => $employee->id,
                    'original_salary' => $originalSalary,
                    'final_salary' => $finalSalary,
                    'working_days' => $workingDays,
                    'attended_days' => $attendedDays,
                    'late_days' => $lateDays,
                    'sick_days' => $sickDays,
                    'permission_days' => $permissionDays,
                    'leave_days' => $leaveDays,
                    'absent_days' => $absentDays,
                    'deduction_amount' => $deduction,
                    'notes' => "Working days: {$workingDays} | Present: {$presentDays} | Late: {$lateDays} | Sick: {$sickDays} | Permission: {$permissionDays} | Leave: {$leaveDays} | Absent: {$absentDays} | Deduction: Rp ".number_format($deduction, 0, '.', ','),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (! empty($payrollDetails)) {
                foreach (array_chunk($payrollDetails, CacheConstants::PAYROLL_BULK_INSERT_CHUNK_SIZE) as $chunk) {
                    DB::table('payroll_details')->insert($chunk);
                }
            }

            $payroll->update(['status' => 'pending']);

            return $payroll->load([
                'payrollDetails.employee.user',
                'payrollDetails.employee.jobInformation.team',
                'payrollDetails.employee.bankInformation',
            ]);
        });
    }

    public function updatePayrollDetail(string $id, array $data): PayrollDetail
    {
        return DB::transaction(function () use ($id, $data) {
            $payrollDetail = PayrollDetail::findOrFail($id);

            if ($payrollDetail->payroll->status === 'paid') {
                throw new \Exception('Cannot update a payroll that has already been paid');
            }

            $updateData = [];
            if (isset($data['notes'])) {
                $updateData['notes'] = $data['notes'];
            }
            if (isset($data['final_salary'])) {
                $updateData['final_salary'] = $data['final_salary'];
            }

            $payrollDetail->update($updateData);

            return $payrollDetail->load([
                'employee.user',
                'employee.jobInformation.team',
                'payroll',
            ]);
        });
    }

    public function markAsPaid(string $payrollId, string $paymentDate): Payroll
    {
        return DB::transaction(function () use ($payrollId, $paymentDate) {
            $payroll = Payroll::findOrFail($payrollId);

            if ($payroll->status === 'paid') {
                throw new \Exception('Payroll has already been paid');
            }

            $payroll->update([
                'status' => 'paid',
                'payment_date' => Carbon::parse($paymentDate)->format('Y-m-d'),
            ]);

            DB::afterCommit(function () use ($payroll) {
                $this->emailService->sendPayrollPaidNotifications($payroll->id);
            });

            return $payroll->loadCount('payrollDetails');
        });
    }

    public function getStatistics()
    {
        $currentMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        // Current month payroll
        $currentPayroll = Payroll::where('salary_month', $currentMonth->format('Y-m-d'))->first();
        $lastPayroll = Payroll::where('salary_month', $lastMonth->format('Y-m-d'))->first();

        $totalEmployeesCurrentMonth = $currentPayroll
            ? $currentPayroll->payrollDetails()->count()
            : 0;

        $totalSalaryCurrentMonth = $currentPayroll
            ? $currentPayroll->payrollDetails()->sum('final_salary')
            : 0;

        $totalSalaryLastMonth = $lastPayroll
            ? $lastPayroll->payrollDetails()->sum('final_salary')
            : 0;

        $paidPayrolls = Payroll::where('status', 'paid')
            ->whereYear('salary_month', now()->year)
            ->count();

        $pendingPayrolls = Payroll::where('status', 'pending')
            ->count();

        // Calculate average salary
        $averageSalary = $totalEmployeesCurrentMonth > 0
            ? $totalSalaryCurrentMonth / $totalEmployeesCurrentMonth
            : 0;

        // Calculate total deductions (difference between original and final salary)
        $totalDeductions = $currentPayroll
            ? $currentPayroll->payrollDetails()->selectRaw('SUM(original_salary - final_salary) as total_deductions')->value('total_deductions')
            : 0;

        return [
            'total_payroll' => $totalEmployeesCurrentMonth,
            'pending_review' => $pendingPayrolls,
            'finalized' => $paidPayrolls,
            'total_amount' => round($totalSalaryCurrentMonth, 2),
            'average_salary' => round($averageSalary, 2),
            'deductions' => round($totalDeductions ?? 0, 2),
            // Backward compatibility
            'total_employees' => $totalEmployeesCurrentMonth,
            'total_salary_current_month' => round($totalSalaryCurrentMonth, 2),
            'total_salary_last_month' => round($totalSalaryLastMonth, 2),
            'salary_change' => $totalSalaryLastMonth > 0
                ? round((($totalSalaryCurrentMonth - $totalSalaryLastMonth) / $totalSalaryLastMonth) * 100, 1)
                : 0,
            'paid_payrolls' => $paidPayrolls,
            'pending_payrolls' => $pendingPayrolls,
        ];
    }

    public function getPayrollStatistics(string $payrollId)
    {
        // Cache key for payroll-specific statistics
        $cacheKey = CacheConstants::CACHE_KEY_PAYROLL_STATISTICS . $payrollId . '_' . now()->format('Y-m-d-H');

        // Cache for 1 hour
        return cache()->remember($cacheKey, CacheConstants::ONE_HOUR, function () use ($payrollId) {
            $payroll = Payroll::findOrFail($payrollId);

            // Get all statistics in optimized queries
            $detailStats = PayrollDetail::where('payroll_id', $payrollId)
                ->selectRaw('
                    COUNT(*) as total_employees,
                    SUM(original_salary) as total_original_salary,
                    SUM(final_salary) as total_final_salary,
                    SUM(original_salary - final_salary) as total_deductions,
                    AVG(final_salary) as average_salary,
                    MAX(final_salary) as highest_salary,
                    MIN(final_salary) as lowest_salary,
                    SUM(attended_days) as total_attended_days,
                    SUM(sick_days) as total_sick_days,
                    SUM(absent_days) as total_absent_days
                ')
                ->first();

            return [
                'payroll_id' => $payroll->id,
                'salary_month' => $payroll->salary_month,
                'period_start' => $payroll->period_start?->format('Y-m-d'),
                'period_end' => $payroll->period_end?->format('Y-m-d'),
                'pay_date' => $payroll->pay_date?->format('Y-m-d'),
                'status' => $payroll->status,
                'payment_date' => $payroll->payment_date?->format('Y-m-d'),
                'processed_date' => $payroll->created_at->format('Y-m-d'),
                'total_employees' => $detailStats->total_employees ?? 0,
                'total_amount' => round($detailStats->total_final_salary ?? 0, 2),
                'total_original_salary' => round($detailStats->total_original_salary ?? 0, 2),
                'total_deductions' => round($detailStats->total_deductions ?? 0, 2),
                'average_salary' => round($detailStats->average_salary ?? 0, 2),
                'highest_salary' => round($detailStats->highest_salary ?? 0, 2),
                'lowest_salary' => round($detailStats->lowest_salary ?? 0, 2),
                'total_attended_days' => $detailStats->total_attended_days ?? 0,
                'total_sick_days' => $detailStats->total_sick_days ?? 0,
                'total_absent_days' => $detailStats->total_absent_days ?? 0,
            ];
        });
    }

    /**
     * Calculate working days (exclude weekends: Saturday & Sunday)
     */
    private function calculateWorkingDays(Carbon $startDate, Carbon $endDate): int
    {
        $workingDays = 0;
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            // Check if not weekend (Saturday = 6, Sunday = 0)
            if (! $currentDate->isWeekend()) {
                $workingDays++;
            }
            $currentDate->addDay();
        }

        return $workingDays;
    }
}

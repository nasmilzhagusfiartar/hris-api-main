<?php

namespace App\Repositories;

use App\Interfaces\ReportRepositoryInterface;
use App\Models\Attendance;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\PayrollDetail;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ReportRepository implements ReportRepositoryInterface
{
    /**
     * Resolve scoped employee IDs allowed for the given user.
     * - Admin / HR / Finance: null (all employees)
     * - Manager (not Admin/HR): only employees in teams managed by this user
     *
     * @return array<int>|null
     */
    public function getScopedEmployeeIds(?User $user = null): ?array
    {
        $user = $user ?: Auth::user();
        if (! $user) {
            return [];
        }

        // Admin, HR, Finance can see all company data
        if ($user->hasAnyRole(['admin', 'hr', 'finance'])) {
            return null;
        }

        if ($user->hasRole('manager')) {
            $managedTeamIds = Team::query()
                ->where('team_lead_id', $user->id)
                ->pluck('id')
                ->all();

            // Also include teams where manager's employeeProfile is team lead or assigned if no direct team_lead_id match
            if (empty($managedTeamIds) && $user->employeeProfile) {
                $managedTeamIds = TeamMember::query()
                    ->where('employee_id', $user->employeeProfile->id)
                    ->whereNull('left_at')
                    ->pluck('team_id')
                    ->all();

                if (empty($managedTeamIds) && $user->employeeProfile->jobInformation?->team_id) {
                    $managedTeamIds = [$user->employeeProfile->jobInformation->team_id];
                }
            }

            if (empty($managedTeamIds)) {
                return [];
            }

            $memberEmployeeIds = TeamMember::query()
                ->whereIn('team_id', $managedTeamIds)
                ->whereNull('left_at')
                ->pluck('employee_id')
                ->all();

            $jobTeamEmployeeIds = EmployeeProfile::query()
                ->whereHas('jobInformation', function (Builder $q) use ($managedTeamIds) {
                    $q->whereIn('team_id', $managedTeamIds);
                })
                ->pluck('id')
                ->all();

            return array_values(array_unique(array_merge($memberEmployeeIds, $jobTeamEmployeeIds)));
        }

        // Regular employee is not allowed in reports
        return [];
    }

    /**
     * Resolve date range from period or explicit start_date / end_date.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDateRange(array $filters): array
    {
        if (! empty($filters['start_date']) && ! empty($filters['end_date'])) {
            return [
                Carbon::parse($filters['start_date'])->startOfDay(),
                Carbon::parse($filters['end_date'])->endOfDay(),
            ];
        }

        $period = $filters['period'] ?? 'last_6_months';
        $end = now()->endOfDay();

        $start = match ($period) {
            'last_3_months' => now()->subMonths(2)->startOfMonth(),
            'this_year' => now()->startOfYear(),
            default => now()->subMonths(5)->startOfMonth(),
        };

        if (! empty($filters['start_date'])) {
            $start = Carbon::parse($filters['start_date'])->startOfDay();
        }
        if (! empty($filters['end_date'])) {
            $end = Carbon::parse($filters['end_date'])->endOfDay();
        }

        return [$start, $end];
    }

    /**
     * Apply scope and optional team / department / employee filters to EmployeeProfile query.
     */
    private function buildScopedEmployeesQuery(array $filters, ?User $user = null): Builder
    {
        $scopedIds = $this->getScopedEmployeeIds($user);

        $query = EmployeeProfile::query()
            ->with(['user', 'jobInformation.team', 'teams'])
            ->whereDoesntHave('user.roles', function (Builder $rq) {
                $rq->where('name', 'security');
            });

        if ($scopedIds !== null) {
            $query->whereIn('id', $scopedIds);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('id', (int) $filters['employee_id']);
        }

        if (! empty($filters['team_id'])) {
            $teamId = (int) $filters['team_id'];
            $query->where(function (Builder $q) use ($teamId) {
                $q->whereHas('jobInformation', fn (Builder $jq) => $jq->where('team_id', $teamId))
                    ->orWhereHas('teamMembers', fn (Builder $tq) => $tq->where('team_id', $teamId)->whereNull('left_at'));
            });
        }

        if (! empty($filters['department'])) {
            $dept = strtolower(trim((string) $filters['department']));
            $query->where(function (Builder $q) use ($dept) {
                $q->whereHas('jobInformation.team', fn (Builder $tq) => $tq->whereRaw('LOWER(department) = ?', [$dept]))
                    ->orWhereHas('teams', fn (Builder $tq) => $tq->whereRaw('LOWER(teams.department) = ?', [$dept]));
            });
        }

        return $query;
    }

    public function getOverview(?User $user = null): array
    {
        $scopedIds = $this->getScopedEmployeeIds($user);

        $empQuery = EmployeeProfile::query()
            ->whereDoesntHave('user.roles', function (Builder $rq) {
                $rq->where('name', 'security');
            });
        if ($scopedIds !== null) {
            $empQuery->whereIn('id', $scopedIds);
        }

        $totalEmployees = (clone $empQuery)->count();
        $prevMonthEmployees = (clone $empQuery)
            ->where('created_at', '<', now()->startOfMonth())
            ->count();

        $employeeGrowth = $prevMonthEmployees > 0
            ? round((($totalEmployees - $prevMonthEmployees) / $prevMonthEmployees) * 100, 1)
            : ($totalEmployees > 0 ? 100.0 : 0.0);

        $attQuery = Attendance::query()
            ->whereBetween('date', [now()->subDays(30)->startOfDay(), now()->endOfDay()]);
        if ($scopedIds !== null) {
            $attQuery->whereIn('employee_id', $scopedIds);
        }

        $totalAtt = (clone $attQuery)->count();
        $presentAtt = (clone $attQuery)->whereIn('status', ['present', 'late'])->count();
        $attendanceRate = $totalAtt > 0 ? round(($presentAtt / $totalAtt) * 100, 1) : 0.0;

        $payrollQuery = PayrollDetail::query();
        if ($scopedIds !== null) {
            $payrollQuery->whereIn('employee_id', $scopedIds);
        }
        $totalPayroll = (float) $payrollQuery->sum('final_salary');

        $projectQuery = Project::query()->where('status', 'active');
        if ($scopedIds !== null) {
            $projectQuery->where(function (Builder $q) use ($scopedIds) {
                $q->whereIn('project_leader_id', $scopedIds)
                    ->orWhereHas('teams.members', fn (Builder $mq) => $mq->whereIn('employee_id', $scopedIds));
            });
        }
        $activeProjects = $projectQuery->count();

        return [
            'total_employees' => $totalEmployees,
            'employee_growth' => $employeeGrowth,
            'attendance_rate' => $attendanceRate,
            'attendance_growth' => 1.5,
            'total_payroll' => round($totalPayroll, 2),
            'payroll_growth' => 0.0,
            'active_projects' => $activeProjects,
            'project_growth' => 0.0,
        ];
    }

    public function getAttendanceReport(array $filters = [], ?User $user = null): array
    {
        [$startDate, $endDate] = $this->resolveDateRange($filters);

        $employees = $this->buildScopedEmployeesQuery($filters, $user)->get();
        $employeeIds = $employees->pluck('id')->all();

        $attendances = empty($employeeIds)
            ? collect()
            : Attendance::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->get();

        $approvedLeaves = empty($employeeIds)
            ? collect()
            : LeaveRequest::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $endDate->format('Y-m-d'))
                ->whereDate('end_date', '>=', $startDate->format('Y-m-d'))
                ->get();

        // 1. Per-employee summary (Hadir, Telat, Izin/Cuti, Sakit, Alpa)
        $attByEmployee = $attendances->groupBy('employee_id');
        $leavesByEmployee = $approvedLeaves->groupBy('employee_id');

        $employeesSummary = [];
        $deptStats = [];

        $totals = [
            'present' => 0,
            'late' => 0,
            'permission' => 0,
            'sick' => 0,
            'absent' => 0,
        ];

        foreach ($employees as $emp) {
            $empAtt = $attByEmployee->get($emp->id, collect());
            $empLeaves = $leavesByEmployee->get($emp->id, collect());

            $present = $empAtt->where('status', 'present')->count();
            $late = $empAtt->where('status', 'late')->count();
            $sick = $empAtt->where('status', 'sick')->count();
            $permission = $empAtt->whereIn('status', ['permission', 'leave'])->count()
                + (int) $empLeaves->sum('total_days');
            $absent = $empAtt->where('status', 'absent')->count();

            $totalRec = $present + $late + $sick + $permission + $absent;
            $rate = $totalRec > 0 ? round((($present + $late) / $totalRec) * 100, 1) : 0.0;

            $team = $emp->jobInformation?->team ?: $emp->teams->first();
            $deptRaw = $team?->department;
            $deptName = is_object($deptRaw) && isset($deptRaw->value)
                ? ucfirst((string) $deptRaw->value)
                : ($deptRaw ? ucfirst((string) $deptRaw) : 'Unassigned');
            $teamName = $team?->name ?? 'Unassigned';

            $employeesSummary[] = [
                'employee_id' => $emp->id,
                'employee_code' => $emp->code ?? ('EMP-' . $emp->id),
                'name' => $emp->user?->name ?? 'N/A',
                'email' => $emp->user?->email ?? '-',
                'department' => $deptName,
                'team_id' => $team?->id,
                'team_name' => $teamName,
                'job_title' => $emp->jobInformation?->job_title ?? '-',
                'present' => $present,
                'late' => $late,
                'permission' => $permission,
                'sick' => $sick,
                'absent' => $absent,
                'total_records' => $totalRec,
                'attendance_rate' => $rate,
            ];

            $totals['present'] += $present;
            $totals['late'] += $late;
            $totals['permission'] += $permission;
            $totals['sick'] += $sick;
            $totals['absent'] += $absent;

            if (! isset($deptStats[$deptName])) {
                $deptStats[$deptName] = ['attended' => 0, 'total' => 0];
            }
            $deptStats[$deptName]['attended'] += ($present + $late);
            $deptStats[$deptName]['total'] += $totalRec;
        }

        // 2. Trend by Month
        $categories = [];
        $trendPresent = [];
        $trendLate = [];
        $trendAbsent = [];

        $cursor = $startDate->copy()->startOfMonth();
        $endMonth = $endDate->copy()->endOfMonth();

        while ($cursor->lte($endMonth)) {
            $monthKey = $cursor->format('Y-m');
            $categories[] = $cursor->format('M Y');

            $monthAtt = $attendances->filter(function ($a) use ($monthKey) {
                return Carbon::parse($a->date)->format('Y-m') === $monthKey;
            });

            $trendPresent[] = $monthAtt->where('status', 'present')->count();
            $trendLate[] = $monthAtt->where('status', 'late')->count();
            $trendAbsent[] = $monthAtt->where('status', 'absent')->count();

            $cursor->addMonth();
        }

        // 3. By department
        $byDepartment = [];
        foreach ($deptStats as $dept => $st) {
            $byDepartment[] = [
                'department' => $dept,
                'rate' => $st['total'] > 0 ? round(($st['attended'] / $st['total']) * 100, 1) : 0.0,
            ];
        }

        return [
            'trend' => [
                'categories' => $categories,
                'present' => $trendPresent,
                'late' => $trendLate,
                'absent' => $trendAbsent,
            ],
            'by_department' => $byDepartment,
            'employees_summary' => $employeesSummary,
            'totals' => $totals,
            'filters' => [
                'period' => $filters['period'] ?? 'last_6_months',
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'team_id' => $filters['team_id'] ?? null,
                'department' => $filters['department'] ?? null,
                'employee_id' => $filters['employee_id'] ?? null,
            ],
        ];
    }

    public function getPayrollReport(array $filters = [], ?User $user = null): array
    {
        [$startDate, $endDate] = $this->resolveDateRange($filters);
        $employees = $this->buildScopedEmployeesQuery($filters, $user)->get();
        $employeeIds = $employees->pluck('id')->all();

        $details = empty($employeeIds)
            ? collect()
            : PayrollDetail::query()
                ->with(['payroll', 'employee.jobInformation.team', 'employee.teams'])
                ->whereIn('employee_id', $employeeIds)
                ->whereHas('payroll', function (Builder $q) use ($startDate, $endDate) {
                    $q->whereBetween('salary_month', [
                        $startDate->copy()->startOfMonth()->toDateString(),
                        $endDate->copy()->endOfMonth()->toDateString(),
                    ]);
                })
                ->get();

        $categories = [];
        $amounts = [];

        $cursor = $startDate->copy()->startOfMonth();
        $endMonth = $endDate->copy()->endOfMonth();

        while ($cursor->lte($endMonth)) {
            $monthKey = $cursor->format('Y-m');
            $categories[] = $cursor->format('M Y');

            $monthSum = $details->filter(function ($d) use ($monthKey) {
                return $d->payroll && Carbon::parse($d->payroll->salary_month)->format('Y-m') === $monthKey;
            })->sum('final_salary');

            $amounts[] = round((float) $monthSum, 2);
            $cursor->addMonth();
        }

        $deptAmounts = [];
        foreach ($details as $detail) {
            $emp = $detail->employee;
            $team = $emp?->jobInformation?->team ?: $emp?->teams?->first();
            $deptRaw = $team?->department;
            $deptName = is_object($deptRaw) && isset($deptRaw->value)
                ? ucfirst((string) $deptRaw->value)
                : ($deptRaw ? ucfirst((string) $deptRaw) : 'Unassigned');

            $deptAmounts[$deptName] = ($deptAmounts[$deptName] ?? 0) + (float) $detail->final_salary;
        }

        // Fallback to monthly_salary from jobInformation if no payroll records exist in range
        if (empty($deptAmounts)) {
            foreach ($employees as $emp) {
                $team = $emp->jobInformation?->team ?: $emp->teams->first();
                $deptRaw = $team?->department;
                $deptName = is_object($deptRaw) && isset($deptRaw->value)
                    ? ucfirst((string) $deptRaw->value)
                    : ($deptRaw ? ucfirst((string) $deptRaw) : 'Unassigned');

                $deptAmounts[$deptName] = ($deptAmounts[$deptName] ?? 0) + (float) ($emp->jobInformation?->monthly_salary ?? 0);
            }
        }

        $byDepartment = [];
        foreach ($deptAmounts as $dept => $amt) {
            $byDepartment[] = [
                'department' => $dept,
                'amount' => round($amt, 2),
            ];
        }

        return [
            'trend' => [
                'categories' => $categories,
                'amount' => $amounts,
            ],
            'by_department' => $byDepartment,
        ];
    }

    public function getEmployeeReport(array $filters = [], ?User $user = null): array
    {
        $employees = $this->buildScopedEmployeesQuery($filters, $user)->get();

        $deptCounts = [];
        $typeCounts = [];

        foreach ($employees as $emp) {
            $team = $emp->jobInformation?->team ?: $emp->teams->first();
            $deptRaw = $team?->department;
            $deptName = is_object($deptRaw) && isset($deptRaw->value)
                ? ucfirst((string) $deptRaw->value)
                : ($deptRaw ? ucfirst((string) $deptRaw) : 'Unassigned');

            $deptCounts[$deptName] = ($deptCounts[$deptName] ?? 0) + 1;

            $empType = $emp->jobInformation?->employment_type ?: 'full_time';
            $typeLabel = ucwords(str_replace(['_', '-'], ' ', (string) $empType));
            $typeCounts[$typeLabel] = ($typeCounts[$typeLabel] ?? 0) + 1;
        }

        $byDepartment = [];
        foreach ($deptCounts as $dept => $count) {
            $byDepartment[] = [
                'department' => $dept,
                'total' => $count,
            ];
        }

        $byEmploymentType = [];
        foreach ($typeCounts as $type => $count) {
            $byEmploymentType[] = [
                'type' => $type,
                'total' => $count,
            ];
        }

        return [
            'by_department' => $byDepartment,
            'by_employment_type' => $byEmploymentType,
            'total_employees' => $employees->count(),
        ];
    }

    public function getActivityLog(?User $user = null): array
    {
        $scopedIds = $this->getScopedEmployeeIds($user);

        $activities = [];

        $attQuery = Attendance::with('employee.user')->orderByDesc('created_at')->limit(5);
        if ($scopedIds !== null) {
            $attQuery->whereIn('employee_id', $scopedIds);
        }

        foreach ($attQuery->get() as $att) {
            $activities[] = [
                'id' => 'att-' . $att->id,
                'actor' => $att->employee?->user?->name ?? 'Karyawan',
                'action' => 'melakukan presensi (' . ucfirst((string) $att->status) . ') pada',
                'target' => Carbon::parse($att->date)->format('d M Y'),
                'created_at' => $att->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ];
        }

        $leaveQuery = LeaveRequest::with('employee.user')->orderByDesc('created_at')->limit(5);
        if ($scopedIds !== null) {
            $leaveQuery->whereIn('employee_id', $scopedIds);
        }

        foreach ($leaveQuery->get() as $leave) {
            $activities[] = [
                'id' => 'leave-' . $leave->id,
                'actor' => $leave->employee?->user?->name ?? 'Karyawan',
                'action' => 'mengajukan permohonan cuti selama',
                'target' => $leave->total_days . ' hari',
                'created_at' => $leave->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ];
        }

        usort($activities, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return array_slice($activities, 0, 8);
    }
}

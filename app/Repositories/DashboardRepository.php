<?php

namespace App\Repositories;

use App\Constants\CacheConstants;
use App\Interfaces\DashboardRepositoryInterface;
use App\Models\Attendance;
use App\Models\Project;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardRepository implements DashboardRepositoryInterface
{
    /**
     * Get employee_profiles.id values belonging to users with the 'security' role.
     *
     * @return array<int>
     */
    private function getSecurityEmployeeIds(): array
    {
        return DB::table('employee_profiles')
            ->join('model_has_roles', function ($join) {
                $join->on('employee_profiles.user_id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', '=', \App\Models\User::class);
            })
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'security')
            ->pluck('employee_profiles.id')
            ->all();
    }

    /**
     * Get managed team IDs and scoped employee IDs for a Manager user.
     *
     * @return array{team_ids: array<int>, employee_ids: array<int>}
     */
    private function getManagerScope($user): array
    {
        $teamIds = Team::query()
            ->where('team_lead_id', $user->id)
            ->pluck('id')
            ->all();

        if (empty($teamIds) && $user->employeeProfile) {
            $teamIds = DB::table('team_members')
                ->where('employee_id', $user->employeeProfile->id)
                ->whereNull('left_at')
                ->pluck('team_id')
                ->all();

            if (empty($teamIds) && $user->employeeProfile->jobInformation?->team_id) {
                $teamIds = [$user->employeeProfile->jobInformation->team_id];
            }
        }

        if (empty($teamIds)) {
            return ['team_ids' => [], 'employee_ids' => []];
        }

        $memberEmpIds = DB::table('team_members')
            ->whereIn('team_id', $teamIds)
            ->whereNull('left_at')
            ->pluck('employee_id')
            ->all();

        $jobEmpIds = DB::table('job_information')
            ->whereIn('team_id', $teamIds)
            ->pluck('employee_id')
            ->all();

        $securityEmpIds = $this->getSecurityEmployeeIds();
        $employeeIds = array_values(array_diff(array_unique(array_merge($memberEmpIds, $jobEmpIds)), $securityEmpIds));

        return [
            'team_ids' => array_values(array_unique($teamIds)),
            'employee_ids' => $employeeIds,
        ];
    }

    public function getStatistics($user = null)
    {
        $user = $user ?: auth()->user();
        $isManagerOnly = $user && $user->hasRole('manager') && ! $user->hasAnyRole(['admin', 'hr']);
        $isFinanceOnly = $user && $user->hasRole('finance') && ! $user->hasAnyRole(['admin', 'hr', 'manager']);

        $securityEmpIds = $this->getSecurityEmployeeIds();

        // If Finance-only role calls getStatistics, return payroll-scoped overview without exposing global HRIS team/project stats
        if ($isFinanceOnly) {
            $paidPayrolls = (int) DB::table('payrolls')->where('status', 'paid')->count();
            $pendingPayrolls = (int) DB::table('payrolls')->where('status', 'pending')->count();
            $totalPayrollEmployees = (int) DB::table('payroll_details')
                ->when(! empty($securityEmpIds), fn ($q) => $q->whereNotIn('employee_id', $securityEmpIds))
                ->distinct('employee_id')
                ->count('employee_id');

            return [
                'scope' => 'finance',
                'employees' => [
                    'total' => $totalPayrollEmployees,
                    'added_this_month' => 0,
                ],
                'teams' => [
                    'total' => 0,
                    'new_teams' => 0,
                ],
                'attendance' => [
                    'rate' => 0,
                    'change' => 0,
                ],
                'tasks' => [
                    'completed' => $paidPayrolls,
                    'change' => 0,
                ],
                'projects' => [
                    'active' => $pendingPayrolls,
                    'new_projects' => 0,
                ],
            ];
        }

        // If Manager role, scope statistics strictly to their managed team(s)
        if ($isManagerOnly) {
            $scope = $this->getManagerScope($user);
            $teamIds = $scope['team_ids'];
            $empIds = $scope['employee_ids'];

            $currentMonth = now()->month;
            $currentYear = now()->year;
            $yesterday = Carbon::yesterday()->toDateString();

            $totalEmployees = count($empIds);
            $addedThisMonth = empty($empIds)
                ? 0
                : (int) DB::table('employee_profiles')
                    ->whereIn('id', $empIds)
                    ->whereMonth('created_at', $currentMonth)
                    ->whereYear('created_at', $currentYear)
                    ->count();

            $totalTeams = count($teamIds);
            $newTeams = empty($teamIds)
                ? 0
                : (int) DB::table('teams')
                    ->whereIn('id', $teamIds)
                    ->whereMonth('created_at', $currentMonth)
                    ->whereYear('created_at', $currentYear)
                    ->count();

            $totalActualAttendances = empty($empIds)
                ? 0
                : (int) DB::table('attendances')
                    ->whereIn('employee_id', $empIds)
                    ->whereMonth('date', $currentMonth)
                    ->whereYear('date', $currentYear)
                    ->whereIn('status', ['present', 'late'])
                    ->count();

            $totalWorkDays = max(1, now()->day);
            $expected = $totalEmployees * $totalWorkDays;
            $attendanceRate = $expected > 0 ? round(($totalActualAttendances / $expected) * 100, 1) : 0;

            $tasksCompleted = empty($empIds)
                ? 0
                : (int) DB::table('project_tasks')
                    ->whereIn('assignee_id', $empIds)
                    ->whereIn('status', ['completed', 'done'])
                    ->count();

            $activeProjects = empty($teamIds)
                ? 0
                : (int) DB::table('project_teams')
                    ->join('projects', 'projects.id', '=', 'project_teams.project_id')
                    ->whereIn('project_teams.team_id', $teamIds)
                    ->where('projects.status', 'active')
                    ->distinct('projects.id')
                    ->count('projects.id');

            return [
                'scope' => 'team',
                'employees' => [
                    'total' => $totalEmployees,
                    'added_this_month' => $addedThisMonth,
                ],
                'teams' => [
                    'total' => $totalTeams,
                    'new_teams' => $newTeams,
                ],
                'attendance' => [
                    'rate' => $attendanceRate,
                    'change' => 0,
                ],
                'tasks' => [
                    'completed' => $tasksCompleted,
                    'change' => 0,
                ],
                'projects' => [
                    'active' => $activeProjects,
                    'new_projects' => 0,
                ],
            ];
        }

        $cacheKey = CacheConstants::CACHE_KEY_DASHBOARD_STATISTICS . 'global_' . now()->format('Y-m-d-H');

        return cache()->remember($cacheKey, CacheConstants::ONE_HOUR, function () use ($securityEmpIds) {
            $startOfMonth = now()->startOfMonth();
            $endOfMonth = now()->endOfMonth();
            $yesterdayStart = Carbon::yesterday()->startOfDay();
            $yesterdayEnd = Carbon::yesterday()->endOfDay();
            $lastWeekStart = Carbon::now()->subWeek()->startOfWeek();
            $lastWeekEnd = Carbon::now()->subWeek()->endOfWeek();

            $totalEmployees = (int) DB::table('employee_profiles')
                ->when(! empty($securityEmpIds), fn ($q) => $q->whereNotIn('id', $securityEmpIds))
                ->whereNull('deleted_at')
                ->count();

            $employeesThisMonth = (int) DB::table('employee_profiles')
                ->when(! empty($securityEmpIds), fn ($q) => $q->whereNotIn('id', $securityEmpIds))
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->count();

            $totalTeams = (int) DB::table('teams')->whereNull('deleted_at')->count();
            $newTeamsThisMonth = (int) DB::table('teams')
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->count();

            // Attendance stats (excluding security role)
            $totalActualAttendances = (int) DB::table('attendances')
                ->when(! empty($securityEmpIds), fn ($q) => $q->whereNotIn('employee_id', $securityEmpIds))
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->count();

            $lastWeekActualAttendances = (int) DB::table('attendances')
                ->when(! empty($securityEmpIds), fn ($q) => $q->whereNotIn('employee_id', $securityEmpIds))
                ->whereBetween('created_at', [$lastWeekStart, $lastWeekEnd])
                ->count();

            // Calculate attendance rates
            $totalWorkDays = now()->day;
            $totalExpectedAttendances = $totalEmployees * $totalWorkDays;

            $attendanceRate = $totalExpectedAttendances > 0
                ? round(($totalActualAttendances / $totalExpectedAttendances) * 100, 1)
                : 0;

            $lastWeekWorkDays = 5;
            $lastWeekExpectedAttendances = $totalEmployees * $lastWeekWorkDays;

            $lastWeekAttendanceRate = $lastWeekExpectedAttendances > 0
                ? round(($lastWeekActualAttendances / $lastWeekExpectedAttendances) * 100, 1)
                : 0;

            $attendanceRateChange = round($attendanceRate - $lastWeekAttendanceRate, 1);

            // Task stats
            $tasksCompleted = (int) DB::table('project_tasks')
                ->whereIn('status', ['completed', 'done'])
                ->count();

            $tasksCompletedYesterday = (int) DB::table('project_tasks')
                ->whereIn('status', ['completed', 'done'])
                ->whereBetween('updated_at', [$yesterdayStart, $yesterdayEnd])
                ->count();

            $tasksChange = $tasksCompleted - $tasksCompletedYesterday;

            // Project stats
            $activeProjects = (int) DB::table('projects')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->count();

            $newProjectsThisMonth = (int) DB::table('projects')
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->count();

            return [
                'scope' => 'global',
                'employees' => [
                    'total' => $totalEmployees,
                    'added_this_month' => $employeesThisMonth,
                ],
                'teams' => [
                    'total' => $totalTeams,
                    'new_teams' => $newTeamsThisMonth,
                ],
                'attendance' => [
                    'rate' => $attendanceRate,
                    'change' => $attendanceRateChange,
                ],
                'tasks' => [
                    'completed' => $tasksCompleted,
                    'change' => $tasksChange,
                ],
                'projects' => [
                    'active' => $activeProjects,
                    'new_projects' => $newProjectsThisMonth,
                ],
            ];
        });
    }

    public function getEmployeeStatistics(int $employeeId)
    {
        $cacheKey = CacheConstants::CACHE_KEY_DASHBOARD_STATISTICS . 'employee_' . $employeeId . '_' . now()->format('Y-m-d-H');

        return cache()->remember($cacheKey, CacheConstants::ONE_HOUR, function () use ($employeeId) {
            $startOfMonth = now()->startOfMonth();
            $endOfMonth = now()->endOfMonth();
            $yesterday = Carbon::yesterday()->toDateString();

            $attendanceStats = DB::table('attendances')
                ->where('employee_id', $employeeId)
                ->whereBetween('date', [$startOfMonth, $endOfMonth])
                ->selectRaw("
                    COUNT(CASE WHEN status = 'present' THEN 1 END) as present_days,
                    COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_days,
                    COUNT(CASE WHEN status = 'sick_leave' THEN 1 END) as sick_days,
                    COUNT(CASE WHEN status = 'late' THEN 1 END) as late_days
                ")
                ->first();

            $totalDays = now()->day;
            $presentDays = (int) ($attendanceStats->present_days ?? 0);
            $attendanceRate = $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 1) : 0;

            $taskStats = DB::table('project_tasks')
                ->where('assignee_id', $employeeId)
                ->selectRaw("
                    COUNT(CASE WHEN status = 'done' THEN 1 END) as total_done,
                    COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
                    COUNT(CASE WHEN status = 'todo' THEN 1 END) as todo,
                    COUNT(CASE WHEN status = 'review' THEN 1 END) as review,
                    COUNT(CASE
                        WHEN status = 'done'
                        AND DATE(updated_at) = ?
                        THEN 1
                    END) as done_yesterday
                ", [$yesterday])
                ->first();

            $assignedProjects = DB::table('project_teams')
                ->join('team_members', 'project_teams.team_id', '=', 'team_members.team_id')
                ->join('projects', 'projects.id', '=', 'project_teams.project_id')
                ->where('team_members.employee_id', $employeeId)
                ->whereNull('team_members.left_at')
                ->where('projects.status', 'active')
                ->selectRaw('COUNT(DISTINCT projects.id) as assigned_count')
                ->first();

            $ledProjects = DB::table('projects')
                ->where('project_leader_id', $employeeId)
                ->where('status', 'active')
                ->selectRaw('COUNT(*) as led_count')
                ->first();

            return [
                'attendance' => [
                    'rate' => $attendanceRate,
                    'present_days' => $presentDays,
                    'absent_days' => (int) ($attendanceStats->absent_days ?? 0),
                    'sick_days' => (int) ($attendanceStats->sick_days ?? 0),
                    'late_days' => (int) ($attendanceStats->late_days ?? 0),
                ],
                'tasks' => [
                    'done' => (int) ($taskStats->total_done ?? 0),
                    'done_yesterday' => (int) ($taskStats->done_yesterday ?? 0),
                    'in_progress' => (int) ($taskStats->in_progress ?? 0),
                    'todo' => (int) ($taskStats->todo ?? 0),
                    'review' => (int) ($taskStats->review ?? 0),
                ],
                'projects' => [
                    'assigned_active' => (int) ($assignedProjects->assigned_count ?? 0),
                    'led_active' => (int) ($ledProjects->led_count ?? 0),
                ],
            ];
        });
    }

    /**
     * Get contextual badge counts for Quick Actions in a single lightweight request
     */
    public function getQuickActionCounts($user): array
    {
        $counts = [
            'pending_leave_requests' => 0,
            'pending_payrolls' => 0,
            'brand_threats_count' => 0,
            'expiring_ssl_count' => 0,
            'breached_emails_count' => 0,
            'failed_logins_24h_count' => 0,
            'my_pending_leaves' => 0,
            'my_pending_tasks' => 0,
        ];

        if (! $user) {
            return $counts;
        }

        $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->toArray() : [];
        $isAdmin = in_array('admin', $roles);
        $isSecurity = in_array('security', $roles);
        $isManagerOnly = in_array('manager', $roles) && ! $isAdmin && ! in_array('hr', $roles);
        $isHr = in_array('hr', $roles);
        $isFinance = in_array('finance', $roles);

        if ($isManagerOnly) {
            // Manager only counts pending leave requests from their managed team
            $scope = $this->getManagerScope($user);
            $counts['pending_leave_requests'] = empty($scope['employee_ids'])
                ? 0
                : (int) DB::table('leave_requests')
                    ->whereIn('employee_id', $scope['employee_ids'])
                    ->where('status', 'pending')
                    ->count();
        } elseif ($isAdmin || $isHr || $user->can('leave-request-approve')) {
            $counts['pending_leave_requests'] = (int) DB::table('leave_requests')
                ->where('status', 'pending')
                ->count();
        }

        if ($isAdmin || $isFinance || $user->can('payroll-list')) {
            $counts['pending_payrolls'] = (int) DB::table('payrolls')
                ->where('status', 'pending')
                ->count();
        }

        if ($isAdmin || $isSecurity || $user->can('cti.brand-protection.view')) {
            $secStats = $this->getSecurityStatistics();
            $counts['brand_threats_count'] = (int) ($secStats['suspicious_domains_count'] ?? 0);
            $counts['expiring_ssl_count'] = (int) ($secStats['expiring_ssl_count'] ?? 0);
            $counts['breached_emails_count'] = (int) ($secStats['breached_emails_count'] ?? 0);
            $counts['failed_logins_24h_count'] = (int) ($secStats['failed_logins_24h_count'] ?? 0);
        }

        $employeeId = $user->employeeProfile?->id;
        if ($employeeId) {
            $counts['my_pending_leaves'] = (int) DB::table('leave_requests')
                ->where('employee_id', $employeeId)
                ->where('status', 'pending')
                ->count();

            $counts['my_pending_tasks'] = (int) DB::table('project_tasks')
                ->where('assignee_id', $employeeId)
                ->whereIn('status', ['todo', 'in_progress'])
                ->count();
        }

        return $counts;
    }

    public function getSecurityStatistics(): array
    {
        // 1. Jumlah suspicious domain baru dari BrandScanResult
        $suspiciousDomainsCount = 0;
        $recentBrandThreats = [];
        if (DB::getSchemaBuilder()->hasTable('brand_scan_results')) {
            $suspiciousDomainsCount = (int) DB::table('brand_scan_results')
                ->distinct('domain')
                ->count('domain');

            $recentBrandThreats = DB::table('brand_scan_results')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'domain', 'fuzzer', 'urlscan_status', 'urlscan_malicious', 'urlscan_score', 'created_at'])
                ->toArray();
        }

        // 2. SSL yang akan expired < 30 hari (dari monitored owned domains)
        // Monitored domains default dates:
        // herointiputra.com: 2026-10-02, hip.co.id: 2027-02-27, toyscity.id: 2026-12-01
        $monitoredSslExpiries = [
            'herointiputra.com' => Carbon::parse('2026-10-02T10:04:21Z'),
            'hip.co.id' => Carbon::parse('2027-02-27T23:59:59Z'),
            'toyscity.id' => Carbon::parse('2026-12-01T00:00:00Z'),
        ];
        $expiringSslCount = 0;
        $now = now();
        foreach ($monitoredSslExpiries as $domain => $expDate) {
            if ($expDate->diffInDays($now, false) >= -30) {
                $expiringSslCount++;
            }
        }

        // 3. Jumlah email karyawan terdampak breach
        $breachedEmailsCount = 0;
        $recentBreaches = [];
        if (DB::getSchemaBuilder()->hasTable('breach_checks')) {
            $breachedEmailsCount = (int) DB::table('breach_checks')
                ->where('is_breached', true)
                ->distinct('email')
                ->count('email');

            $recentBreaches = DB::table('breach_checks')
                ->orderByDesc('checked_at')
                ->limit(5)
                ->get(['id', 'email', 'is_breached', 'breaches', 'checked_at'])
                ->map(function ($row) {
                    $row->breaches = is_string($row->breaches)
                        ? (json_decode($row->breaches, true) ?: [])
                        : ($row->breaches ?: []);

                    return $row;
                })
                ->toArray();
        }

        // 4. Login gagal 24 jam terakhir
        $failedLogins24hCount = 0;
        if (DB::getSchemaBuilder()->hasTable('telescope_entries')) {
            try {
                $sinceUtc = now()->subHours(24)->setTimezone('UTC')->format('Y-m-d H:i:s');
                $failedLogins24hCount = (int) DB::table('telescope_entries')
                    ->where('type', 'request')
                    ->where('created_at', '>=', $sinceUtc)
                    ->where('content', 'like', '%/login%')
                    ->get(['content'])
                    ->filter(function ($entry) {
                        $c = json_decode((string) $entry->content, true) ?: [];
                        $uri = (string) ($c['uri'] ?? '');
                        $status = (int) ($c['response_status'] ?? 200);

                        return stripos($uri, '/login') !== false && $status >= 400;
                    })
                    ->count();
            } catch (\Throwable $e) {
                $failedLogins24hCount = 0;
            }
        }

        // 5. Users without MFA enabled
        $usersWithoutMfaCount = (int) DB::table('users')
            ->whereNull('deleted_at')
            ->whereNull('mfa_enabled_at')
            ->count();

        // 6. Failed MFA attempts in the last 24 hours
        $mfaFailed24hCount = 0;
        if (DB::getSchemaBuilder()->hasTable('telescope_entries')) {
            try {
                $sinceUtc = now()->subHours(24)->setTimezone('UTC')->format('Y-m-d H:i:s');
                $mfaFailed24hCount = (int) DB::table('telescope_entries')
                    ->where('type', 'request')
                    ->where('created_at', '>=', $sinceUtc)
                    ->where('content', 'like', '%"mfa_event":"mfa_failed"%')
                    ->count();
            } catch (\Throwable $e) {
                $mfaFailed24hCount = 0;
            }
        }

        return [
            'suspicious_domains_count' => $suspiciousDomainsCount,
            'expiring_ssl_count' => $expiringSslCount,
            'breached_emails_count' => $breachedEmailsCount,
            'failed_logins_24h_count' => $failedLogins24hCount,
            'users_without_mfa_count' => $usersWithoutMfaCount,
            'mfa_failed_24h_count' => $mfaFailed24hCount,
            'recent_brand_threats' => $recentBrandThreats,
            'recent_breaches' => $recentBreaches,
        ];
    }

    /**
     * Clear dashboard statistics cache
     */
    public function clearDashboardCache(): void
    {
        cache()->forget(CacheConstants::CACHE_KEY_DASHBOARD_STATISTICS . now()->format('Y-m-d-H'));
    }
}

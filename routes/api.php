<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandScanController;
use App\Http\Controllers\BreachCheckController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeProfileController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OptionController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectTaskController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'mfa.enrolled'])->get('search', [SearchController::class, 'search']);

Route::prefix('v1')
    ->group(function () {

        Route::get('health', function () {
            return response()->json([
                'status' => 'ok',
                'message' => 'HRIS API is running smoothly!',
                'timestamp' => now()->toIso8601String(),
            ]);
        });

        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');
        Route::post('login/mfa-verify', [AuthController::class, 'mfaVerify'])->middleware('throttle:5,1');

        Route::middleware('auth:sanctum')->group(function () {
            // Allowed even when MFA enrollment is pending for enforced roles
            Route::get('me', [AuthController::class, 'me']);
            Route::put('me', [AuthController::class, 'updateProfile']);
            Route::post('logout', [AuthController::class, 'logout']);

            // MFA self-service endpoints
            Route::get('mfa/status', [MfaController::class, 'status']);
            Route::post('mfa/setup', [MfaController::class, 'setup']);
            Route::post('mfa/enable', [MfaController::class, 'enable']);
            Route::post('mfa/disable', [MfaController::class, 'disable']);
            Route::post('mfa/recovery-codes/regenerate', [MfaController::class, 'regenerateRecoveryCodes']);

            // All other endpoints require MFA enrollment if user's role is enforced
            Route::middleware('mfa.enrolled')->group(function () {
                // Admin MFA reset
                Route::post('users/{user}/mfa/reset', [MfaController::class, 'resetUser']);

            Route::get('teams/statistics', [TeamController::class, 'getStatistics']);
            Route::get('teams/all/paginated', [TeamController::class, 'getAllPaginated']);
            Route::get('teams/{team}/statistics', [TeamController::class, 'getTeamStatistics']);
            Route::get('teams/{team}/chart-data', [TeamController::class, 'getTeamChartData']);
            Route::post('teams/{team}/add-member', [TeamController::class, 'addMember']);
            Route::post('teams/{team}/remove-member', [TeamController::class, 'removeMember']);
            Route::apiResource('teams', TeamController::class);

            // Stores CRUD routes (super-admin, admin, hr)
            Route::get('stores/all/paginated', [StoreController::class, 'getAllPaginated']);
            Route::apiResource('stores', StoreController::class);

            Route::get('my-profile', [EmployeeProfileController::class, 'getMyProfile']);
            Route::get('my-team', [EmployeeProfileController::class, 'getMyTeam']);
            Route::get('my-team/members', [EmployeeProfileController::class, 'getMyTeamMembers']);
            Route::get('my-team/projects', [EmployeeProfileController::class, 'getMyTeamProjects']);
            Route::get('employees/statistics', [EmployeeProfileController::class, 'getStatistics']);
            Route::get('employees/{id}/performance-statistics', [EmployeeProfileController::class, 'getPerformanceStatistics']);
            Route::get('employees/all/paginated', [EmployeeProfileController::class, 'getAllPaginated']);
            Route::apiResource('employees', EmployeeProfileController::class);

            Route::get('projects/statistics', [ProjectController::class, 'getStatistics']);
            Route::get('projects/all/paginated', [ProjectController::class, 'getAllPaginated']);
            Route::apiResource('projects', ProjectController::class);

            Route::apiResource('project-tasks', ProjectTaskController::class);
            Route::get('project-tasks/all/paginated', [ProjectTaskController::class, 'getAllPaginated']);

            Route::get('attendances/all/paginated', [AttendanceController::class, 'getAllPaginated']);
            Route::get('attendances/statistics', [AttendanceController::class, 'getStatistics']);
            Route::get('my-attendances', [AttendanceController::class, 'getMyAttendances']);
            Route::get('my-attendance-statistics', [AttendanceController::class, 'getMyAttendanceStatistics']);
            Route::get('attendances/last-attendance', [AttendanceController::class, 'getLastAttendance']);
            Route::post('attendances/check-in', [AttendanceController::class, 'checkIn']);
            Route::post('attendances/check-out', [AttendanceController::class, 'checkOut']);
            Route::apiResource('attendances', AttendanceController::class);

            Route::apiResource('leave-requests', LeaveRequestController::class);
            Route::get('leave-requests/all/paginated', [LeaveRequestController::class, 'getAllPaginated']);
            Route::get('my-leave-requests', [LeaveRequestController::class, 'getMyLeaveRequests']);
            Route::post('leave-requests/approve/{id}', [LeaveRequestController::class, 'approve']);
            Route::post('leave-requests/reject/{id}', [LeaveRequestController::class, 'reject']);

            // Payroll routes
            Route::get('payrolls/statistics', [PayrollController::class, 'getStatistics']);
            Route::get('payrolls/all/paginated', [PayrollController::class, 'getAllPaginated']);
            Route::post('payrolls/generate', [PayrollController::class, 'generate']);
            Route::get('payrolls/{id}/statistics', [PayrollController::class, 'getPayrollStatistics']);
            Route::get('payrolls/{id}/details', [PayrollController::class, 'getDetails']); // Paginated details
            Route::get('payrolls/{id}/export-excel', [PayrollController::class, 'exportExcel']);
            Route::post('payrolls/{id}/mark-as-paid', [PayrollController::class, 'markAsPaid']);
            Route::put('payroll-details/{id}', [PayrollController::class, 'updateDetail']);
            Route::apiResource('payrolls', PayrollController::class)->only(['index', 'show']);

            // Options routes
            Route::get('options/departments', [OptionController::class, 'getDepartments']);
            Route::get('options/employment-types', [OptionController::class, 'getEmploymentTypes']);
            Route::get('options/job-statuses', [OptionController::class, 'getJobStatuses']);
            Route::get('options/task-priorities', [OptionController::class, 'getTaskPriorities']);
            Route::get('options/task-statuses', [OptionController::class, 'getTaskStatuses']);
            Route::get('options/leave-types', [OptionController::class, 'getLeaveTypes']);
            Route::get('options/work-locations', [OptionController::class, 'getWorkLocations']);
            Route::get('options/skill-levels', [OptionController::class, 'getSkillLevels']);

            // Dashboard & Global Search routes
            Route::get('search', [SearchController::class, 'search']);
            Route::get('dashboard/statistics', [DashboardController::class, 'getStatistics']);
            Route::get('dashboard/my-statistics', [DashboardController::class, 'getEmployeeStatistics']);
            Route::get('dashboard/security-statistics', [DashboardController::class, 'getSecurityStatistics']);
            Route::get('dashboard/quick-action-counts', [DashboardController::class, 'getQuickActionCounts']);

            // RBAC routes (Roles & Permissions)
            Route::get('roles/all/paginated', [RoleController::class, 'getAllPaginated']);
            Route::post('roles/{id}/assign-permissions', [RoleController::class, 'assignPermissions']);
            Route::apiResource('roles', RoleController::class);

            Route::get('permissions/grouped', [PermissionController::class, 'getGrouped']);
            Route::get('permissions/all/paginated', [PermissionController::class, 'getAllPaginated']);
            Route::apiResource('permissions', PermissionController::class);

            // CTI / OSINT routes
            Route::post('cti/breach-checks', [BreachCheckController::class, 'check']);
            Route::post('cti/breach-checks/check-all', [BreachCheckController::class, 'checkAll']);
            Route::get('cti/breach-checks/all/paginated', [BreachCheckController::class, 'getAllPaginated']);

            Route::post('cti/brand-scans', [BrandScanController::class, 'store']);
            Route::get('cti/brand-scans/latest', [BrandScanController::class, 'latest']);
            Route::get('cti/brand-scans/{id}', [BrandScanController::class, 'show']);
            Route::post('cti/brand-scan-results/{id}/urlscan', [BrandScanController::class, 'submitUrlscan']);
            Route::get('cti/brand-scan-results/{id}/urlscan', [BrandScanController::class, 'getUrlscan']);

            Route::get('cti/audit-logs', [AuditLogController::class, 'index']);

            // Reports routes
            Route::get('reports/overview', [ReportController::class, 'overview']);
            Route::get('reports/attendance', [ReportController::class, 'attendance']);
            Route::get('reports/attendance/export-excel', [ReportController::class, 'exportAttendanceExcel']);
            Route::get('reports/payroll', [ReportController::class, 'payroll']);
            Route::get('reports/employees', [ReportController::class, 'employees']);
            Route::get('reports/activity-log', [ReportController::class, 'activityLog']);

            // Settings routes
            Route::get('settings', [SettingController::class, 'index']);
            Route::match(['put', 'post'], 'settings/company', [SettingController::class, 'updateCompany']);
            Route::match(['put', 'post'], 'settings/notifications', [SettingController::class, 'updateNotifications']);
            Route::post('settings/change-password', [SettingController::class, 'changePassword']);

            // Notifications routes
            Route::get('notifications', [NotificationController::class, 'index']);
            Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
            Route::patch('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
            Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
            });
        });
    });

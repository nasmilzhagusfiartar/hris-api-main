<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Interfaces\DashboardRepositoryInterface;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;

class DashboardController extends Controller implements HasMiddleware
{
    private DashboardRepositoryInterface $dashboardRepository;

    public function __construct(DashboardRepositoryInterface $dashboardRepository)
    {
        $this->dashboardRepository = $dashboardRepository;
    }

    public static function middleware()
    {
        return [
            new Middleware(PermissionMiddleware::using(['dashboard-view']), only: ['getStatistics', 'getEmployeeStatistics', 'getQuickActionCounts']),
            new Middleware(PermissionMiddleware::using(['cti.brand-protection.view']), only: ['getSecurityStatistics']),
        ];
    }

    /**
     * Get CTI security summary statistics for Security / Admin dashboard
     */
    public function getSecurityStatistics()
    {
        try {
            $stats = $this->dashboardRepository->getSecurityStatistics();

            return ResponseHelper::jsonResponse(true, 'Security Dashboard Statistics Retrieved Successfully', $stats, 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Internal Server Error: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * Get dashboard quick action badge counts
     */
    public function getQuickActionCounts()
    {
        try {
            $counts = $this->dashboardRepository->getQuickActionCounts(auth()->user());

            return ResponseHelper::jsonResponse(true, 'Quick Action Counts Retrieved Successfully', $counts, 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Internal Server Error: '.$e->getMessage(), null, 500);
        }
    }

    /**
     * Get dashboard statistics (filtered by role; forbidden for Employee & Security)
     */
    public function getStatistics()
    {
        try {
            $user = auth()->user();

            if (! $user || (! $user->hasAnyRole(['admin', 'hr', 'manager', 'finance']))) {
                return ResponseHelper::jsonResponse(
                    false,
                    "You don't have permission to access global HRIS statistics.",
                    null,
                    403
                );
            }

            $statistics = $this->dashboardRepository->getStatistics($user);

            return ResponseHelper::jsonResponse(true, 'Dashboard Statistics Retrieved Successfully', $statistics, 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Internal Server Error: '.$e->getMessage(), null, 500);
        }
    }

    public function getEmployeeStatistics()
    {
        try {
            $employeeId = auth()->user()->employeeProfile->id;
            $statistics = $this->dashboardRepository->getEmployeeStatistics($employeeId);

            return ResponseHelper::jsonResponse(true, 'Employee Dashboard Statistics Retrieved Successfully', $statistics, 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Internal Server Error: '.$e->getMessage(), null, 500);
        }
    }
}

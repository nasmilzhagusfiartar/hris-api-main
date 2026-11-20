<?php

namespace App\Http\Controllers;

use App\Exports\AttendanceReportExport;
use App\Helpers\ResponseHelper;
use App\Interfaces\ReportRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Middleware\PermissionMiddleware;

class ReportController extends Controller implements HasMiddleware
{
    public function __construct(
        private ReportRepositoryInterface $reportRepository
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware(
                PermissionMiddleware::using(['report-view']),
                only: ['overview', 'attendance', 'payroll', 'employees', 'activityLog', 'exportAttendanceExcel']
            ),
        ];
    }

    public function overview()
    {
        try {
            $data = $this->reportRepository->getOverview(Auth::user());

            return ResponseHelper::jsonResponse(true, 'Report Overview Retrieved Successfully', $data, 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to load report overview.', null, 500);
        }
    }

    public function attendance(Request $request)
    {
        $validated = $request->validate([
            'period' => 'nullable|string|in:last_3_months,last_6_months,this_year',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'team_id' => 'nullable|integer',
            'department' => 'nullable|string|max:100',
            'store_id' => 'nullable|integer',
            'employment_type' => 'nullable|string|max:50',
            'vendor_name' => 'nullable|string|max:255',
            'employee_id' => 'nullable|integer',
        ]);

        try {
            $data = $this->reportRepository->getAttendanceReport($validated, Auth::user());

            return ResponseHelper::jsonResponse(true, 'Attendance Report Retrieved Successfully', $data, 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to load attendance report.', null, 500);
        }
    }

    public function exportAttendanceExcel(Request $request)
    {
        $validated = $request->validate([
            'period' => 'nullable|string|in:last_3_months,last_6_months,this_year',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'team_id' => 'nullable|integer',
            'department' => 'nullable|string|max:100',
            'store_id' => 'nullable|integer',
            'employment_type' => 'nullable|string|max:50',
            'vendor_name' => 'nullable|string|max:255',
            'employee_id' => 'nullable|integer',
        ]);

        $report = $this->reportRepository->getAttendanceReport($validated, Auth::user());
        $filename = 'attendance-report-' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(new AttendanceReportExport($report['employees_summary'] ?? []), $filename);
    }

    public function payroll(Request $request)
    {
        $validated = $request->validate([
            'period' => 'nullable|string|in:last_3_months,last_6_months,this_year',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'team_id' => 'nullable|integer',
            'department' => 'nullable|string|max:100',
            'store_id' => 'nullable|integer',
        ]);

        try {
            $data = $this->reportRepository->getPayrollReport($validated, Auth::user());

            return ResponseHelper::jsonResponse(true, 'Payroll Report Retrieved Successfully', $data, 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to load payroll report.', null, 500);
        }
    }

    public function employees(Request $request)
    {
        $validated = $request->validate([
            'period' => 'nullable|string|in:last_3_months,last_6_months,this_year',
            'team_id' => 'nullable|integer',
            'department' => 'nullable|string|max:100',
            'store_id' => 'nullable|integer',
            'employment_type' => 'nullable|string|max:50',
            'vendor_name' => 'nullable|string|max:255',
        ]);

        try {
            $data = $this->reportRepository->getEmployeeReport($validated, Auth::user());

            return ResponseHelper::jsonResponse(true, 'Employee Report Retrieved Successfully', $data, 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to load employee report.', null, 500);
        }
    }

    public function activityLog()
    {
        try {
            $data = $this->reportRepository->getActivityLog(Auth::user());

            return ResponseHelper::jsonResponse(true, 'Activity Log Retrieved Successfully', $data, 200);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to load activity log.', null, 500);
        }
    }
}

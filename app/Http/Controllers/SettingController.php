<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\SettingResource;
use App\Interfaces\SettingRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Middleware\PermissionMiddleware;

class SettingController extends Controller implements HasMiddleware
{
    public function __construct(
        private SettingRepositoryInterface $settingRepository
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware(PermissionMiddleware::using(['setting-view']), only: ['index', 'updateNotifications', 'changePassword']),
            new Middleware(PermissionMiddleware::using(['setting-edit']), only: ['updateCompany']),
        ];
    }

    public function index()
    {
        try {
            $data = $this->settingRepository->getSettings(Auth::user());

            return ResponseHelper::jsonResponse(
                true,
                'Settings Retrieved Successfully',
                new SettingResource($data),
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to load system settings.', null, 500);
        }
    }

    public function updateCompany(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'timezone' => 'required|string|in:Asia/Jakarta,Asia/Makassar,Asia/Jayapura',
            'work_days_per_week' => 'required|integer|min:1|max:7',
        ]);

        try {
            $company = $this->settingRepository->updateCompanySettings($validated);

            return ResponseHelper::jsonResponse(
                true,
                'Company settings saved successfully.',
                new SettingResource($company),
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to save company settings.', null, 500);
        }
    }

    public function updateNotifications(Request $request)
    {
        $validated = $request->validate([
            'email_leave_request' => 'nullable|boolean',
            'email_payroll_processed' => 'nullable|boolean',
            'email_attendance_alert' => 'nullable|boolean',
            'push_task_assigned' => 'nullable|boolean',
            'push_task_due_soon' => 'nullable|boolean',
        ]);

        try {
            $notifications = $this->settingRepository->updateNotificationSettings(Auth::user(), $validated);

            return ResponseHelper::jsonResponse(
                true,
                'Notification preferences updated successfully.',
                new SettingResource($notifications),
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to update notification preferences.', null, 500);
        }
    }

    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $this->settingRepository->changePassword(
                Auth::user(),
                $validated['current_password'],
                $validated['new_password']
            );

            return ResponseHelper::jsonResponse(true, 'Password updated successfully.', null, 200);
        } catch (\InvalidArgumentException $e) {
            return ResponseHelper::jsonResponse(false, $e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to update password.', null, 500);
        }
    }
}

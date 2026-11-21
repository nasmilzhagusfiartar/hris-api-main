<?php

namespace App\Repositories;

use App\Interfaces\SettingRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class SettingRepository implements SettingRepositoryInterface
{
    private const COMPANY_SETTINGS_PATH = 'settings/company.json';

    private function defaultCompanySettings(): array
    {
        return [
            'name' => config('app.name', 'HIP HRIS'),
            'email' => 'hr@herointiputra.com',
            'phone' => '+62 21 555 0100',
            'address' => 'Jakarta, Indonesia',
            'timezone' => 'Asia/Jakarta',
            'work_days_per_week' => 5,
            'payroll_cutoff_day' => 15,
            'payroll_pay_day' => 28,
            'work_days_by_location' => [
                'office' => [1, 2, 3, 4, 5],
                'hybrid' => [1, 2, 3, 4, 5],
                'remote' => [1, 2, 3, 4, 5],
                'store' => [1, 2, 3, 4, 5, 6],
            ],
        ];
    }

    private function defaultNotificationSettings(): array
    {
        return [
            'email_leave_request' => true,
            'email_payroll_processed' => true,
            'email_attendance_alert' => false,
            'push_task_assigned' => true,
            'push_task_due_soon' => true,
        ];
    }

    public function getSettings(User $user): array
    {
        return [
            'company' => $this->getCompanySettings(),
            'notifications' => $this->getUserNotificationSettings($user->id),
        ];
    }

    public function getCompanySettings(): array
    {
        $defaults = $this->defaultCompanySettings();

        if (Storage::disk('local')->exists(self::COMPANY_SETTINGS_PATH)) {
            $decoded = json_decode((string) Storage::disk('local')->get(self::COMPANY_SETTINGS_PATH), true);
            if (is_array($decoded)) {
                $merged = array_merge($defaults, $decoded);
                if (isset($decoded['work_days_by_location']) && is_array($decoded['work_days_by_location'])) {
                    $merged['work_days_by_location'] = array_merge(
                        $defaults['work_days_by_location'],
                        $decoded['work_days_by_location']
                    );
                }

                return $merged;
            }
        }

        return $defaults;
    }

    private function getUserNotificationSettings(int $userId): array
    {
        $defaults = $this->defaultNotificationSettings();
        $path = "settings/notifications/user_{$userId}.json";

        if (Storage::disk('local')->exists($path)) {
            $decoded = json_decode((string) Storage::disk('local')->get($path), true);
            if (is_array($decoded)) {
                return array_merge($defaults, $decoded);
            }
        }

        return $defaults;
    }

    public function updateCompanySettings(array $data): array
    {
        $current = $this->getCompanySettings();
        $updated = array_merge($current, [
            'name' => $data['name'] ?? $current['name'],
            'email' => $data['email'] ?? $current['email'],
            'phone' => $data['phone'] ?? $current['phone'],
            'address' => $data['address'] ?? $current['address'],
            'timezone' => $data['timezone'] ?? $current['timezone'],
            'work_days_per_week' => isset($data['work_days_per_week']) ? (int) $data['work_days_per_week'] : $current['work_days_per_week'],
            'payroll_cutoff_day' => isset($data['payroll_cutoff_day']) ? (int) $data['payroll_cutoff_day'] : $current['payroll_cutoff_day'],
            'payroll_pay_day' => isset($data['payroll_pay_day']) ? (int) $data['payroll_pay_day'] : $current['payroll_pay_day'],
        ]);

        if (isset($data['work_days_by_location']) && is_array($data['work_days_by_location'])) {
            $locations = $current['work_days_by_location'];
            foreach ($data['work_days_by_location'] as $loc => $days) {
                if (is_array($days)) {
                    $cleanDays = array_values(array_unique(array_filter(
                        array_map('intval', $days),
                        fn (int $d) => $d >= 1 && $d <= 7
                    )));
                    sort($cleanDays);
                    if (! empty($cleanDays)) {
                        $locations[$loc] = $cleanDays;
                    }
                }
            }
            $updated['work_days_by_location'] = $locations;
        }

        Storage::disk('local')->put(self::COMPANY_SETTINGS_PATH, json_encode($updated, JSON_PRETTY_PRINT));

        return $updated;
    }

    public function updateNotificationSettings(User $user, array $data): array
    {
        $current = $this->getUserNotificationSettings($user->id);
        $updated = $current;

        foreach (array_keys($this->defaultNotificationSettings()) as $key) {
            if (array_key_exists($key, $data)) {
                $updated[$key] = (bool) $data[$key];
            }
        }

        $path = "settings/notifications/user_{$user->id}.json";
        Storage::disk('local')->put($path, json_encode($updated, JSON_PRETTY_PRINT));

        return $updated;
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): bool
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new \InvalidArgumentException('Current password is incorrect.', 422);
        }

        $user->update([
            'password' => Hash::make($newPassword),
        ]);

        return true;
    }
}

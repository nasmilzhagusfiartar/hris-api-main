<?php

namespace App\Interfaces;

use App\Models\User;

interface SettingRepositoryInterface
{
    public function getSettings(User $user): array;

    public function updateCompanySettings(array $data): array;

    public function updateNotificationSettings(User $user, array $data): array;

    public function changePassword(User $user, string $currentPassword, string $newPassword): bool;
}

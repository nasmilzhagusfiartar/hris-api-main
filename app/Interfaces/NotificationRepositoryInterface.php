<?php

namespace App\Interfaces;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

interface NotificationRepositoryInterface
{
    public function getPaginated(User $user, int $perPage = 10): LengthAwarePaginator;

    public function getUnreadCount(User $user): int;

    public function markAsRead(User $user, string $notificationId): DatabaseNotification;

    public function markAllAsRead(User $user): int;
}

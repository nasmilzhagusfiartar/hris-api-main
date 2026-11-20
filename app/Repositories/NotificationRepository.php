<?php

namespace App\Repositories;

use App\Interfaces\NotificationRepositoryInterface;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

class NotificationRepository implements NotificationRepositoryInterface
{
    public function getPaginated(User $user, int $perPage = 10): LengthAwarePaginator
    {
        return $user->notifications()
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function getUnreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function markAsRead(User $user, string $notificationId): DatabaseNotification
    {
        /** @var DatabaseNotification $notification */
        $notification = $user->notifications()->where('id', $notificationId)->firstOrFail();

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return $notification->fresh();
    }

    public function markAllAsRead(User $user): int
    {
        return $user->unreadNotifications()->update([
            'read_at' => now(),
        ]);
    }
}

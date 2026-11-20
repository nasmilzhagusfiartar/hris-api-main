<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\PaginateResource;
use App\Interfaces\NotificationRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationRepositoryInterface $notificationRepository
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'row_per_page' => 'nullable|integer|min:1|max:50',
            'page' => 'nullable|integer|min:1',
        ]);

        try {
            $perPage = (int) ($validated['row_per_page'] ?? 10);
            $notifications = $this->notificationRepository->getPaginated(Auth::user(), $perPage);

            return ResponseHelper::jsonResponse(
                true,
                'Notifications Retrieved Successfully',
                PaginateResource::make($notifications, NotificationResource::class),
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to load notifications.', null, 500);
        }
    }

    public function unreadCount()
    {
        try {
            $count = $this->notificationRepository->getUnreadCount(Auth::user());

            return ResponseHelper::jsonResponse(
                true,
                'Unread Notification Count Retrieved Successfully',
                ['unread_count' => $count],
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to load unread notification count.', null, 500);
        }
    }

    public function markAsRead(string $id)
    {
        try {
            $notification = $this->notificationRepository->markAsRead(Auth::user(), $id);

            return ResponseHelper::jsonResponse(
                true,
                'Notification marked as read.',
                new NotificationResource($notification),
                200
            );
        } catch (ModelNotFoundException $e) {
            return ResponseHelper::jsonResponse(false, 'Notification not found.', null, 404);
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to update notification status.', null, 500);
        }
    }

    public function markAllAsRead()
    {
        try {
            $updatedCount = $this->notificationRepository->markAllAsRead(Auth::user());

            return ResponseHelper::jsonResponse(
                true,
                'All notifications marked as read.',
                ['updated_count' => $updatedCount],
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::jsonResponse(false, 'Failed to mark all notifications as read.', null, 500);
        }
    }
}

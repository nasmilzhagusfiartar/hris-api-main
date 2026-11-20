<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRequestRejected extends Notification implements ShouldQueue
{
    use Queueable;

    protected LeaveRequest $leaveRequest;

    /**
     * Create a new notification instance.
     */
    public function __construct(LeaveRequest $leaveRequest)
    {
        $this->leaveRequest = $leaveRequest;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Leave Request Rejected')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Unfortunately, your leave request could not be approved.')
            ->line('**Request Details:**')
            ->line('Leave Type: '.$this->leaveRequest->leave_type->label())
            ->line('Start Date: '.$this->leaveRequest->start_date->format('d M Y'))
            ->line('End Date: '.$this->leaveRequest->end_date->format('d M Y'))
            ->line('Total Days: '.$this->leaveRequest->total_days.' working day(s)')
            ->line('Status: **Rejected**')
            ->action('View Details', url('/my-attendance'))
            ->line('If you have any questions, please contact the HR Team for further information.')
            ->salutation('Thank you, HIP HRIS HR Team');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $leaveTypeLabel = is_object($this->leaveRequest->leave_type) && method_exists($this->leaveRequest->leave_type, 'label')
            ? $this->leaveRequest->leave_type->label()
            : (string) ($this->leaveRequest->leave_type->value ?? $this->leaveRequest->leave_type);

        return [
            'type' => 'leave_request_rejected',
            'title' => 'Leave Request Rejected',
            'message' => "Your leave request ({$leaveTypeLabel}) for {$this->leaveRequest->total_days} day(s) could not be approved.",
            'route_name' => 'employee.attendance.my-attendances',
            'leave_request_id' => $this->leaveRequest->id,
            'leave_type' => $this->leaveRequest->leave_type,
            'start_date' => $this->leaveRequest->start_date,
            'end_date' => $this->leaveRequest->end_date,
            'total_days' => $this->leaveRequest->total_days,
            'status' => 'rejected',
        ];
    }
}

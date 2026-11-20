<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : (json_decode((string) $this->data, true) ?: []);

        $type = $data['type'] ?? class_basename((string) $this->type);
        $title = $data['title'] ?? $this->resolveDefaultTitle($type);
        $message = $data['message'] ?? $this->resolveDefaultMessage($type, $data);
        $routeName = $data['route_name'] ?? $this->resolveDefaultRoute($type);

        return [
            'id' => $this->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'route_name' => $routeName,
            'route_params' => $data['route_params'] ?? null,
            'data' => $data,
            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'time_ago' => $this->created_at?->diffForHumans(),
        ];
    }

    private function resolveDefaultTitle(string $type): string
    {
        return match ($type) {
            'LeaveRequestCreated', 'leave_request_created' => 'Leave Request Submitted',
            'LeaveRequestApproved', 'leave_request_approved' => 'Leave Request Approved',
            'LeaveRequestRejected', 'leave_request_rejected' => 'Leave Request Rejected',
            'PayrollPaid', 'payroll_paid' => 'Payslip Paid',
            default => 'System Notification',
        };
    }

    private function resolveDefaultMessage(string $type, array $data): string
    {
        $days = $data['total_days'] ?? null;

        return match ($type) {
            'LeaveRequestCreated', 'leave_request_created' => $days
                ? "Your leave request for {$days} day(s) is pending approval."
                : 'Your leave request is pending approval.',
            'LeaveRequestApproved', 'leave_request_approved' => $days
                ? "Your leave request for {$days} day(s) has been approved."
                : 'Your leave request has been approved.',
            'LeaveRequestRejected', 'leave_request_rejected' => $days
                ? "Your leave request for {$days} day(s) could not be approved."
                : 'Your leave request has been rejected.',
            'PayrollPaid', 'payroll_paid' => 'Your salary payment has been processed.',
            default => 'You have a new update in HIP HRIS.',
        };
    }

    private function resolveDefaultRoute(string $type): string
    {
        return match ($type) {
            'LeaveRequestCreated', 'leave_request_created',
            'LeaveRequestApproved', 'leave_request_approved',
            'LeaveRequestRejected', 'leave_request_rejected' => 'employee.attendance.my-attendances',
            'PayrollPaid', 'payroll_paid' => 'employee.payslips',
            default => 'admin.dashboard',
        };
    }
}

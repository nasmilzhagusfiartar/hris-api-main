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
            'LeaveRequestCreated', 'leave_request_created' => 'Pengajuan Cuti Diproses',
            'LeaveRequestApproved', 'leave_request_approved' => 'Permohonan Cuti Disetujui',
            'LeaveRequestRejected', 'leave_request_rejected' => 'Permohonan Cuti Ditolak',
            'PayrollPaid', 'payroll_paid' => 'Slip Gaji Telah Dibayar',
            default => 'Notifikasi Sistem',
        };
    }

    private function resolveDefaultMessage(string $type, array $data): string
    {
        $days = $data['total_days'] ?? null;

        return match ($type) {
            'LeaveRequestCreated', 'leave_request_created' => $days
                ? "Pengajuan cuti selama {$days} hari sedang menunggu persetujuan."
                : 'Pengajuan cuti Anda sedang menunggu persetujuan.',
            'LeaveRequestApproved', 'leave_request_approved' => $days
                ? "Permohonan cuti selama {$days} hari telah disetujui."
                : 'Permohonan cuti Anda telah disetujui.',
            'LeaveRequestRejected', 'leave_request_rejected' => $days
                ? "Permohonan cuti selama {$days} hari tidak dapat disetujui."
                : 'Permohonan cuti Anda telah ditolak.',
            'PayrollPaid', 'payroll_paid' => 'Pembayaran gaji Anda telah selesai diproses.',
            default => 'Anda memiliki pembaruan baru di HIP HRIS.',
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

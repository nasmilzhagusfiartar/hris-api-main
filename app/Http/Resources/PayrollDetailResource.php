<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $deduction = $this->deduction_amount !== null && (float) $this->deduction_amount > 0
            ? (float) $this->deduction_amount
            : max(0.0, (float) $this->original_salary - (float) $this->final_salary);

        return [
            'id' => $this->id,
            'payroll_id' => $this->payroll_id,
            'employee_id' => $this->employee_id,
            'original_salary' => (float) $this->original_salary,
            'final_salary' => (float) $this->final_salary,
            'working_days' => (int) ($this->working_days ?? 0),
            'attended_days' => (int) ($this->attended_days ?? 0),
            'present_days' => max(0, (int) ($this->attended_days ?? 0) - (int) ($this->late_days ?? 0)),
            'late_days' => (int) ($this->late_days ?? 0),
            'sick_days' => (int) ($this->sick_days ?? 0),
            'permission_days' => (int) ($this->permission_days ?? 0),
            'leave_days' => (int) ($this->leave_days ?? 0),
            'absent_days' => (int) ($this->absent_days ?? 0),
            'deduction_amount' => round($deduction, 2),
            'notes' => $this->notes,
            'employee' => new EmployeeProfileResource($this->whenLoaded('employee')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

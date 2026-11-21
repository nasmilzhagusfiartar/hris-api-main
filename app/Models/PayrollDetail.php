<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollDetail extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'payroll_id',
        'employee_id',
        'original_salary',
        'final_salary',
        'working_days',
        'attended_days',
        'late_days',
        'sick_days',
        'permission_days',
        'leave_days',
        'absent_days',
        'deduction_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'original_salary' => 'decimal:2',
            'final_salary' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
        ];
    }

    public function payroll()
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee()
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }
}

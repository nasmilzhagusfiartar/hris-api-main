<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Services\UserAccountLifecycleService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobInformation extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::saved(function (JobInformation $job): void {
            if (! $job->wasRecentlyCreated && ! $job->wasChanged('status')) {
                return;
            }

            $user = $job->employee?->user()->withTrashed()->first();
            if (! $user) {
                return;
            }

            $newStatus = $job->status instanceof \BackedEnum ? $job->status->value : (string) $job->status;
            $lifecycle = app(UserAccountLifecycleService::class);

            if (JobStatus::isInactiveStatus($newStatus)) {
                $lifecycle->deactivate(
                    $user,
                    "status changed to {$newStatus}",
                    [
                        'employee_profile_id' => $job->employee_id,
                        'status' => $newStatus,
                        'end_date' => $job->end_date?->format('Y-m-d'),
                    ]
                );
            } elseif (! $job->wasRecentlyCreated && $user->is_active === false) {
                $lifecycle->reactivate(
                    $user,
                    "status changed to {$newStatus}",
                    [
                        'employee_profile_id' => $job->employee_id,
                        'status' => $newStatus,
                    ]
                );
            }
        });
    }

    protected $fillable = [
        'employee_id',
        'job_title',
        'team_id',
        'department',
        'store_id',
        'years_experience',
        'status',
        'employment_type',
        'vendor_name',
        'work_location',
        'start_date',
        'end_date',
        'monthly_salary',
        'skill_level',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'monthly_salary' => 'decimal:2',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}

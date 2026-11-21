<?php

namespace App\Models;

use App\Services\UserAccountLifecycleService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EmployeeProfile extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    protected static function booted(): void
    {
        static::deleting(function (EmployeeProfile $profile): void {
            $user = $profile->user()->withTrashed()->first();
            if ($user && $user->hasRole('super-admin')) {
                throw new HttpException(403, 'Super Admin user cannot be deleted.');
            }
        });

        static::deleted(function (EmployeeProfile $profile): void {
            $user = $profile->user()->withTrashed()->first();
            if (! $user) {
                return;
            }

            app(UserAccountLifecycleService::class)->deactivate(
                $user,
                'employee deleted',
                ['employee_profile_id' => $profile->id]
            );
        });

        static::restored(function (EmployeeProfile $profile): void {
            $user = $profile->user()->withTrashed()->first();
            if (! $user) {
                return;
            }

            app(UserAccountLifecycleService::class)->reactivate(
                $user,
                'employee restored',
                ['employee_profile_id' => $profile->id]
            );
        });
    }

    protected $fillable = [
        'user_id',
        'code',
        'identity_number',
        'phone',
        'date_of_birth',
        'gender',
        'hobby',
        'place_of_birth',
        'address',
        'city',
        'postal_code',
        'preferred_language',
        'additional_notes',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'phone' => $this->phone,
            'identity_number' => $this->identity_number,
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function jobInformation()
    {
        return $this->hasOne(JobInformation::class, 'employee_id');
    }

    public function bankInformation()
    {
        return $this->hasOne(BankInformation::class, 'employee_id');
    }

    public function emergencyContacts()
    {
        return $this->hasMany(EmergencyContact::class, 'employee_id');
    }

    public function teamMembers()
    {
        return $this->hasMany(TeamMember::class, 'employee_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class, 'id', 'id')
            ->join('team_members', 'teams.id', '=', 'team_members.team_id')
            ->where('team_members.employee_id', $this->id);
    }

    public function teams()
    {
        return $this->belongsToMany(Team::class, 'team_members', 'employee_id', 'team_id');
    }

    public function ledProjects()
    {
        return $this->hasMany(Project::class, 'project_leader_id');
    }

    public function assignedTasks()
    {
        return $this->hasMany(ProjectTask::class, 'assignee_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'employee_id');
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class, 'employee_id');
    }

    public function approvedLeaveRequests()
    {
        return $this->hasMany(LeaveRequest::class, 'approved_by');
    }

    public function payrollDetails()
    {
        return $this->hasMany(PayrollDetail::class, 'employee_id');
    }
}

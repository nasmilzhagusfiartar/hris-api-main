<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Symfony\Component\HttpKernel\Exception\HttpException;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            if ($user->hasRole('super-admin')) {
                $superAdminCount = static::role('super-admin', 'sanctum')->count();
                if ($superAdminCount <= 1) {
                    throw new HttpException(403, 'Cannot delete the last Super Admin in the system.');
                }
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'profile_photo',
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'mfa_enabled_at',
        'mfa_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_enabled_at' => 'datetime',
            'mfa_recovery_codes' => 'encrypted:array',
        ];
    }

    public function hasMfaEnabled(): bool
    {
        return $this->mfa_enabled_at !== null && ! empty($this->mfa_secret);
    }

    public function requiresMfa(): bool
    {
        if (! config('mfa.enabled', true)) {
            return false;
        }

        $enforcedRoles = config('mfa.enforce_roles', []);
        if (empty($enforcedRoles)) {
            return false;
        }

        $userRoles = $this->getRoleNames()
            ->map(static fn (string $role): string => strtolower($role))
            ->all();

        return count(array_intersect($userRoles, $enforcedRoles)) > 0;
    }

    public function employeeProfile()
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function isVendorEmployee(): bool
    {
        $type = $this->employeeProfile?->jobInformation?->employment_type;

        return is_string($type) && strtolower($type) === 'vendor';
    }

    public function leadTeams()
    {
        return $this->hasMany(Team::class, 'team_lead_id');
    }
}

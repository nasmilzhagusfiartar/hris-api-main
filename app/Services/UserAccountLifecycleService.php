<?php

namespace App\Services;

use App\Models\User;

class UserAccountLifecycleService
{
    public function __construct(
        private MfaAuditLogger $auditLogger
    ) {}

    /**
     * Deactivate a user account, revoke all Sanctum tokens, and record account_deactivated in Audit Log.
     * Super Admin accounts are strictly protected and never deactivated.
     *
     * @param  array<string, mixed>  $meta
     */
    public function deactivate(User $user, string $reason, array $meta = []): bool
    {
        if ($user->hasRole('super-admin')) {
            return false;
        }

        $wasActive = $user->is_active !== false;

        if ($wasActive) {
            $user->forceFill(['is_active' => false])->save();
        }

        $user->tokens()->delete();

        $this->auditLogger->log(
            'account_deactivated',
            auth()->user(),
            request(),
            array_merge([
                'target_user_id' => $user->id,
                'target_email' => $user->email,
                'reason' => $reason,
                'deactivated_at' => now()->toIso8601String(),
            ], $meta),
            200
        );

        return true;
    }

    /**
     * Reactivate a user account and record account_reactivated in Audit Log.
     *
     * @param  array<string, mixed>  $meta
     */
    public function reactivate(User $user, string $reason, array $meta = []): bool
    {
        if ($user->trashed()) {
            $user->restore();
        }

        if ($user->is_active !== true) {
            $user->forceFill(['is_active' => true])->save();
        }

        $this->auditLogger->log(
            'account_reactivated',
            auth()->user(),
            request(),
            array_merge([
                'target_user_id' => $user->id,
                'target_email' => $user->email,
                'reason' => $reason,
                'reactivated_at' => now()->toIso8601String(),
            ], $meta),
            200
        );

        return true;
    }
}

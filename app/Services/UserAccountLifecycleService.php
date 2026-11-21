<?php

namespace App\Services;

use App\Constants\CacheConstants;
use App\Models\EmployeeProfile;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;

class UserAccountLifecycleService
{
    public function __construct(
        private MfaAuditLogger $auditLogger
    ) {}

    /**
     * Deactivate a user account, revoke all Sanctum tokens, remove from all teams,
     * unassign as team lead, and record events in Audit Log.
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

        $this->removeFromTeamsAndUnassignLead($user, $reason, $meta);

        return true;
    }

    /**
     * End all active team memberships (via left_at) and clear team_lead_id for a deactivated/deleted employee.
     *
     * @param  array<string, mixed>  $meta
     */
    public function removeFromTeamsAndUnassignLead(User $user, string $reason, array $meta = []): void
    {
        $affectedTeamIds = [];

        $profileId = $meta['employee_profile_id']
            ?? EmployeeProfile::withTrashed()->where('user_id', $user->id)->value('id');

        if ($profileId) {
            $activeMemberships = TeamMember::where('employee_id', $profileId)
                ->whereNull('left_at')
                ->get();

            foreach ($activeMemberships as $membership) {
                $membership->update(['left_at' => now()]);
                $affectedTeamIds[] = $membership->team_id;

                $this->auditLogger->log(
                    'team_member_removed',
                    auth()->user(),
                    request(),
                    [
                        'team_id' => $membership->team_id,
                        'employee_profile_id' => $profileId,
                        'target_user_id' => $user->id,
                        'target_email' => $user->email,
                        'reason' => $reason,
                    ],
                    200
                );
            }
        }

        $ledTeams = Team::where('team_lead_id', $user->id)->get();
        foreach ($ledTeams as $team) {
            $team->update(['team_lead_id' => null]);
            $affectedTeamIds[] = $team->id;

            $this->auditLogger->log(
                'team_lead_unassigned',
                auth()->user(),
                request(),
                [
                    'team_id' => $team->id,
                    'team_name' => $team->name,
                    'target_user_id' => $user->id,
                    'target_email' => $user->email,
                    'reason' => $reason,
                ],
                200
            );
        }

        $this->clearTeamCaches(array_values(array_unique($affectedTeamIds)));
    }

    /**
     * @param  list<int>  $teamIds
     */
    private function clearTeamCaches(array $teamIds): void
    {
        if (empty($teamIds)) {
            return;
        }

        cache()->forget(CacheConstants::CACHE_KEY_TEAM_STATISTICS.now()->format('Y-m-d-H'));
        cache()->forget(CacheConstants::CACHE_KEY_TEAM_STATISTICS.now()->subHour()->format('Y-m-d-H'));

        foreach ($teamIds as $teamId) {
            cache()->forget(CacheConstants::CACHE_KEY_TEAM_STATISTICS.$teamId.'_'.now()->format('Y-m-d-H'));
            cache()->forget(CacheConstants::CACHE_KEY_TEAM_STATISTICS.$teamId.'_'.now()->subHour()->format('Y-m-d-H'));
            cache()->forget(CacheConstants::CACHE_KEY_TEAM_CHART_DATA.$teamId.'_'.now()->format('Y-m-d'));
            cache()->forget(CacheConstants::CACHE_KEY_TEAM_CHART_DATA.$teamId.'_'.now()->subDay()->format('Y-m-d'));
        }
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

<?php

namespace App\Repositories;

use App\Constants\CacheConstants;
use App\DTOs\TeamDto;
use App\Interfaces\TeamRepositoryInterface;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TeamRepository implements TeamRepositoryInterface
{
    public function getAll(
        ?string $search,
        ?int $leaderId,
        ?string $status,
        ?string $department,
        ?int $limit,
        bool $execute,
        ?int $storeId = null
    ): Builder|Collection {
        $authUser = auth()->user();
        $managedStoreIds = ($authUser && $authUser->isStoreHead()) ? $authUser->managedStoreIds() : null;

        $query = Team::with([
            'store',
            'leader',
            'leader.employeeProfile',
            'leader.employeeProfile.jobInformation',
        ])
            ->when($managedStoreIds !== null, function ($q) use ($managedStoreIds) {
                $q->whereIn('store_id', $managedStoreIds);
            })
            ->where(function ($query) use ($search, $leaderId, $status, $department, $storeId) {
                if ($search) {
                    $query->search($search);
                }

                if ($leaderId) {
                    $query->where('team_lead_id', $leaderId);
                }

                if ($status) {
                    $query->where('status', $status);
                }

                if ($department) {
                    $query->where('department', $department);
                }

                if ($storeId) {
                    $query->where('store_id', $storeId);
                }
            })->withCount('members')->withCount('projects')->orderByDesc('created_at');

        if ($limit) {
            $query->take($limit);
        }

        if ($execute) {
            return $query->get();
        }

        return $query;
    }

    public function getAllPaginated(
        ?string $search,
        ?int $leaderId,
        ?string $status,
        ?string $department,
        ?int $rowPerPage,
        ?int $storeId = null
    ): LengthAwarePaginator {
        $query = $this->getAll(
            $search,
            $leaderId,
            $status,
            $department,
            null,
            false,
            $storeId
        );

        return $query->paginate($rowPerPage);
    }

    public function getById(
        string $id
    ): Team {
        $team = Team::with([
            'store',
            'leader',
            'leader.employeeProfile',
            'leader.employeeProfile.jobInformation',
            'members',
            'members.employee',
            'members.employee.user',
            'members.employee.jobInformation',
        ])->withCount(['members', 'projects'])->findOrFail($id);

        $authUser = auth()->user();
        if ($authUser && $authUser->isStoreHead()) {
            $managedStoreIds = $authUser->managedStoreIds();
            if (! in_array((int) $team->store_id, $managedStoreIds, true)) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'You do not have permission to access teams outside your store.');
            }
        }

        $team->setAttribute('recent_activities', $this->buildRecentActivities($team));

        return $team;
    }

    /**
     * Build up to 5 latest real activities for this team from Audit Logs (telescope_entries)
     * and Project Tasks belonging to this team's projects.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentActivities(Team $team): array
    {
        $activities = [];

        // 1. Audit Log entries related to this team (add/remove member, update team / lead)
        if (\Illuminate\Support\Facades\Schema::hasTable('telescope_entries')) {
            try {
                $uriExpr = DB::getDriverName() === 'sqlite'
                    ? "json_extract(content, '$.uri')"
                    : "JSON_UNQUOTE(JSON_EXTRACT(content, '$.uri'))";
                $methodExpr = DB::getDriverName() === 'sqlite'
                    ? "json_extract(content, '$.method')"
                    : "JSON_UNQUOTE(JSON_EXTRACT(content, '$.method'))";

                $teamUriPattern = "%/api/v1/teams/{$team->id}%";
                $logs = DB::table('telescope_entries')
                    ->where('type', 'request')
                    ->whereRaw("{$uriExpr} LIKE ?", [$teamUriPattern])
                    ->whereRaw("{$methodExpr} IN ('POST', 'PUT', 'PATCH', 'DELETE')")
                    ->orderByDesc('sequence')
                    ->limit(10)
                    ->get(['uuid', 'content', 'created_at']);

                foreach ($logs as $row) {
                    $content = json_decode((string) $row->content, true) ?: [];
                    $uri = (string) ($content['uri'] ?? '');
                    $method = strtoupper((string) ($content['method'] ?? ''));
                    $status = (int) ($content['response_status'] ?? 200);
                    if ($status >= 400) {
                        continue;
                    }

                    $actorName = $content['user']['name'] ?? 'System';
                    $type = 'team_updated';
                    $title = "Team settings updated by {$actorName}";

                    if (str_contains($uri, '/add-member')) {
                        $type = 'member_added';
                        $title = "Team member added by {$actorName}";
                    } elseif (str_contains($uri, '/remove-member')) {
                        $type = 'member_removed';
                        $title = "Team member removed by {$actorName}";
                    } elseif (! empty($content['payload']['team_lead_id'])) {
                        $type = 'lead_changed';
                        $title = "Team lead updated by {$actorName}";
                    } elseif ($method === 'DELETE') {
                        continue;
                    }

                    $activities[] = [
                        'id' => 'audit-'.$row->uuid,
                        'type' => $type,
                        'title' => $title,
                        'actor' => $actorName,
                        'status' => $type,
                        'timestamp' => \Carbon\Carbon::parse($row->created_at, 'UTC')->toIso8601String(),
                    ];
                }
            } catch (\Throwable $e) {
                // Ignore audit query errors so team detail always loads
            }
        }

        // 2. Project tasks belonging to this team's projects
        try {
            $tasks = \App\Models\ProjectTask::with(['project:id,name', 'assignee.user:id,name'])
                ->whereHas('project.teams', function ($q) use ($team) {
                    $q->where('teams.id', $team->id);
                })
                ->orderByDesc('updated_at')
                ->limit(5)
                ->get();

            foreach ($tasks as $task) {
                $statusVal = $task->status instanceof \BackedEnum ? $task->status->value : (string) $task->status;
                $projectName = $task->project?->name ? " ({$task->project->name})" : '';
                $activities[] = [
                    'id' => 'task-'.$task->id,
                    'type' => $statusVal === 'done' || $statusVal === 'completed' ? 'task_completed' : 'task_updated',
                    'title' => ($task->name ?? 'Task').$projectName,
                    'actor' => $task->assignee?->user?->name ?? null,
                    'status' => $statusVal,
                    'timestamp' => ($task->updated_at ?? $task->created_at)?->toIso8601String(),
                ];
            }
        } catch (\Throwable $e) {
            // Ignore if relation fails
        }

        // Sort newest first & return max 5
        usort($activities, fn ($a, $b) => strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? '')));

        return array_slice($activities, 0, 5);
    }

    public function create(array $data): Team
    {
        return DB::transaction(function () use ($data) {
            $teamDto = TeamDto::fromArray($data);
            $team = Team::create($teamDto->toArray());

            if (isset($data['icon'])) {
                $iconPath = \App\Helpers\SafeUploadHelper::storeImage($data['icon'], 'team-icons', 'public');
                $team->update(['icon' => $iconPath]);
            }

            if ($team->team_lead_id) {
                $leader = User::with('employeeProfile')->find($team->team_lead_id);
                if ($leader && $leader->employeeProfile) {
                    TeamMember::updateOrCreate(
                        [
                            'team_id' => $team->id,
                            'employee_id' => $leader->employeeProfile->id,
                        ],
                        [
                            'joined_at' => now(),
                            'left_at' => null,
                        ]
                    );
                }
            }

            // Clear caches
            $this->clearTeamCaches($team->id);

            return $team->load(['store', 'leader', 'members']);
        });
    }

    public function update(string $id, array $data): Team
    {
        return DB::transaction(function () use ($id, $data) {
            $team = Team::findOrFail($id);
            $oldLeaderId = $team->team_lead_id;
            $oldIcon = $team->icon;

            $teamDto = TeamDto::fromArrayForUpdate($data, $team);
            $team->update($teamDto->toArray());

            if (! empty($data['remove_icon']) && ! isset($data['icon'])) {
                if ($oldIcon && Storage::disk('public')->exists($oldIcon)) {
                    Storage::disk('public')->delete($oldIcon);
                }
                $team->update(['icon' => null]);
            } elseif (isset($data['icon']) && $data['icon'] instanceof \Illuminate\Http\UploadedFile) {
                if ($oldIcon && Storage::disk('public')->exists($oldIcon)) {
                    Storage::disk('public')->delete($oldIcon);
                }

                $iconPath = \App\Helpers\SafeUploadHelper::storeImage($data['icon'], 'team-icons', 'public');
                $team->update(['icon' => $iconPath]);
            }

            if ($team->team_lead_id !== $oldLeaderId) {
                if ($oldLeaderId) {
                    $oldLeader = User::with('employeeProfile')->find($oldLeaderId);
                    if ($oldLeader && $oldLeader->employeeProfile) {
                        TeamMember::where('team_id', $team->id)
                            ->where('employee_id', $oldLeader->employeeProfile->id)
                            ->whereNull('left_at')
                            ->update(['left_at' => now()]);
                    }
                }

                if ($team->team_lead_id) {
                    $newLeader = User::with('employeeProfile')->find($team->team_lead_id);
                    if ($newLeader && $newLeader->employeeProfile) {
                        TeamMember::updateOrCreate(
                            [
                                'team_id' => $team->id,
                                'employee_id' => $newLeader->employeeProfile->id,
                            ],
                            [
                                'joined_at' => now(),
                                'left_at' => null,
                            ]
                        );
                    }
                }
            }

            // Clear caches
            $this->clearTeamCaches($team->id);

            return $team->fresh(['store', 'leader', 'members']);
        });
    }

    public function delete(string $id): Team
    {
        return DB::transaction(function () use ($id) {
            $team = Team::findOrFail($id);

            if ($team->icon && Storage::disk('public')->exists($team->icon)) {
                Storage::disk('public')->delete($team->icon);
            }

            // Clear caches before deleting
            $this->clearTeamCaches($team->id);

            $team->delete();

            return $team;
        });
    }

    public function getStatistics(): array
    {
        // Cache key for statistics
        $cacheKey = CacheConstants::CACHE_KEY_TEAM_STATISTICS.now()->format('Y-m-d-H');

        // Cache for 1 hour
        return cache()->remember($cacheKey, CacheConstants::ONE_HOUR, function () {
            // Get all team statistics in a single optimized query
            $teamStats = Team::selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN status = ? THEN 1 END) as active,
                COUNT(CASE WHEN YEAR(created_at) = ? AND MONTH(created_at) = ? THEN 1 END) as added_this_month,
                COUNT(CASE WHEN status = ? AND created_at <= ? THEN 1 END) as active_last_week
            ', [
                'active',
                now()->year,
                now()->month,
                'active',
                now()->subWeek()->endOfWeek(),
            ])->first();

            // Get all member statistics in a single optimized query (active non-deleted employees only)
            $memberStats = TeamMember::whereHas('employee', function ($q) {
                $q->whereNull('employee_profiles.deleted_at')
                    ->whereHas('user', fn ($u) => $u->where('is_active', true))
                    ->whereHas('jobInformation', fn ($j) => $j->whereNotIn('status', \App\Enums\JobStatus::inactiveValues()));
            })->selectRaw('
                COUNT(CASE WHEN left_at IS NULL THEN 1 END) as total,
                COUNT(CASE WHEN left_at IS NULL AND YEAR(joined_at) = ? AND MONTH(joined_at) = ? THEN 1 END) as added_this_month,
                COUNT(CASE WHEN left_at IS NULL AND joined_at < ? THEN 1 END) as last_month_total
            ', [
                now()->year,
                now()->month,
                now()->startOfMonth(),
            ])->first();

            $totalTeams = $teamStats->total;
            $totalMembers = $memberStats->total;
            $activeTeams = $teamStats->active;
            $activeTeamsLastWeek = $teamStats->active_last_week;
            $teamsThisMonth = $teamStats->added_this_month;

            // Calculate changes
            $activeTeamsChange = $activeTeams - $activeTeamsLastWeek;
            $membersChange = $totalMembers - $memberStats->last_month_total;

            // Calculate average team size
            $averageTeamSize = $totalTeams > 0 ? round($totalMembers / $totalTeams, 1) : 0;

            return [
                'total' => $totalTeams,
                'added_this_month' => $teamsThisMonth,
                'active' => $activeTeams,
                'active_change' => $activeTeamsChange,
                'members' => $totalMembers,
                'members_change' => $membersChange,
                'average_size' => $averageTeamSize,
                'new_teams' => $teamsThisMonth, // Same as added_this_month
            ];
        });
    }

    public function getTeamStatistics(string $teamId): array
    {
        // Cache key for team-specific statistics
        $cacheKey = CacheConstants::CACHE_KEY_TEAM_STATISTICS.$teamId.'_'.now()->format('Y-m-d-H');

        // Cache for 1 hour
        return cache()->remember($cacheKey, CacheConstants::ONE_HOUR, function () use ($teamId) {
            $team = Team::withCount('members')->findOrFail($teamId);

            // Get project statistics in a single query
            $projectStats = Project::whereHas('teams', function ($q) use ($teamId) {
                $q->where('teams.id', $teamId);
            })->selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN status = ? THEN 1 END) as in_progress
            ', ['in_progress'])->first();

            // Get task statistics in a single optimized query
            $taskStats = DB::table('project_tasks')
                ->join('projects', 'project_tasks.project_id', '=', 'projects.id')
                ->join('project_teams', 'projects.id', '=', 'project_teams.project_id')
                ->where('project_teams.team_id', $teamId)
                ->selectRaw('
                    COUNT(*) as total_tasks,
                    COUNT(CASE WHEN project_tasks.status = ? THEN 1 END) as completed_tasks,
                    COUNT(CASE WHEN YEAR(project_tasks.created_at) = ? AND MONTH(project_tasks.created_at) = ? THEN 1 END) as tasks_this_month,
                    COUNT(CASE WHEN project_tasks.status = ? AND YEAR(project_tasks.updated_at) = ? AND MONTH(project_tasks.updated_at) = ? THEN 1 END) as completed_this_month
                ', [
                    'done',
                    now()->year,
                    now()->month,
                    'done',
                    now()->year,
                    now()->month,
                ])->first();

            // Calculate performance metrics
            $teamPerformance = $taskStats->total_tasks > 0
                ? round(($taskStats->completed_tasks / $taskStats->total_tasks) * 100)
                : 0;

            $completionRate = $taskStats->tasks_this_month > 0
                ? round(($taskStats->completed_this_month / $taskStats->tasks_this_month) * 100)
                : 0;

            return [
                'active_members' => $team->members_count,
                'projects_assigned' => $projectStats->total ?? 0,
                'projects_in_progress' => $projectStats->in_progress ?? 0,
                'team_performance' => $teamPerformance,
                'completion_rate' => $completionRate,
            ];
        });
    }

    public function getTeamChartData(string $teamId): array
    {
        // Cache key for chart data
        $cacheKey = CacheConstants::CACHE_KEY_TEAM_CHART_DATA.$teamId.'_'.now()->format('Y-m-d');

        // Cache for 24 hours
        return cache()->remember($cacheKey, CacheConstants::ONE_DAY, function () use ($teamId) {
            $months = [];

            // Prepare month dates
            for ($i = 3; $i >= 0; $i--) {
                $date = now()->subMonths($i);
                $key = $date->format('Y-m');
                $months[$key] = $date->format('M');
            }

            // Get all member data in a single query (active non-deleted employees only)
            $memberStats = TeamMember::where('team_id', $teamId)
                ->whereNull('left_at')
                ->whereHas('employee', function ($q) {
                    $q->whereNull('employee_profiles.deleted_at')
                        ->whereHas('user', fn ($u) => $u->where('is_active', true))
                        ->whereHas('jobInformation', fn ($j) => $j->whereNotIn('status', \App\Enums\JobStatus::inactiveValues()));
                })
                ->whereIn(DB::raw('DATE_FORMAT(joined_at, "%Y-%m")'), array_keys($months))
                ->selectRaw('DATE_FORMAT(joined_at, "%Y-%m") as month, COUNT(*) as count')
                ->groupBy('month')
                ->pluck('count', 'month')
                ->toArray();

            // Get all task data in a single optimized query
            $taskStats = DB::table('project_tasks')
                ->join('projects', 'project_tasks.project_id', '=', 'projects.id')
                ->join('project_teams', 'projects.id', '=', 'project_teams.project_id')
                ->where('project_teams.team_id', $teamId)
                ->whereIn(DB::raw('DATE_FORMAT(project_tasks.created_at, "%Y-%m")'), array_keys($months))
                ->selectRaw('DATE_FORMAT(project_tasks.created_at, "%Y-%m") as month, COUNT(*) as count')
                ->groupBy('month')
                ->pluck('count', 'month')
                ->toArray();

            // Prepare final arrays with 0 defaults
            $memberData = [];
            $taskData = [];
            $monthLabels = [];

            foreach ($months as $key => $label) {
                $monthLabels[] = $label;
                $memberData[] = $memberStats[$key] ?? 0;
                $taskData[] = $taskStats[$key] ?? 0;
            }

            return [
                'months' => $monthLabels,
                'member_growth' => $memberData,
                'task_growth' => $taskData,
            ];
        });
    }

    public function addMember(string $teamId, int $employeeId): TeamMember
    {
        return DB::transaction(function () use ($teamId, $employeeId) {
            $team = Team::findOrFail($teamId);

            // Check if member already exists and is active
            $existingMember = TeamMember::where('team_id', $teamId)
                ->where('employee_id', $employeeId)
                ->whereNull('left_at')
                ->first();

            if ($existingMember) {
                throw new \Exception('Employee is already a member of this team');
            }

            // Single active location rule: end active memberships in teams at OTHER locations
            if ($team->store_id !== null) {
                TeamMember::where('employee_id', $employeeId)
                    ->whereNull('left_at')
                    ->whereHas('team', function ($q) use ($team) {
                        $q->where('store_id', '!=', $team->store_id)
                            ->orWhereNull('store_id');
                    })
                    ->update(['left_at' => now()]);

                \App\Models\JobInformation::where('employee_id', $employeeId)
                    ->update([
                        'store_id' => $team->store_id,
                        'team_id' => $team->id,
                    ]);
            }

            // Add new member
            $member = TeamMember::updateOrCreate(
                [
                    'team_id' => $teamId,
                    'employee_id' => $employeeId,
                ],
                [
                    'joined_at' => now(),
                    'left_at' => null,
                ]
            );

            // Clear caches
            $this->clearTeamCaches($teamId);

            return $member->load(['employee', 'employee.user', 'employee.jobInformation.store']);
        });
    }

    public function removeMember(string $teamId, int $employeeId): TeamMember
    {
        return DB::transaction(function () use ($teamId, $employeeId) {
            $member = TeamMember::where('team_id', $teamId)
                ->where('employee_id', $employeeId)
                ->whereNull('left_at')
                ->first();

            if (! $member) {
                throw new \Exception('Employee is not a member of this team');
            }

            $member->update(['left_at' => now()]);

            $this->clearTeamCaches($teamId);

            return $member->load(['employee', 'employee.user', 'employee.jobInformation']);
        });
    }

    /**
     * Clear all caches related to team statistics
     */
    private function clearTeamCaches(?string $teamId = null): void
    {
        // Clear general statistics cache
        cache()->forget(CacheConstants::CACHE_KEY_TEAM_STATISTICS.now()->format('Y-m-d-H'));
        cache()->forget(CacheConstants::CACHE_KEY_TEAM_STATISTICS.now()->subHour()->format('Y-m-d-H'));

        // Clear team-specific caches if teamId is provided
        if ($teamId) {
            cache()->forget(CacheConstants::CACHE_KEY_TEAM_STATISTICS.$teamId.'_'.now()->format('Y-m-d-H'));
            cache()->forget(CacheConstants::CACHE_KEY_TEAM_STATISTICS.$teamId.'_'.now()->subHour()->format('Y-m-d-H'));
            cache()->forget(CacheConstants::CACHE_KEY_TEAM_CHART_DATA.$teamId.'_'.now()->format('Y-m-d'));
            cache()->forget(CacheConstants::CACHE_KEY_TEAM_CHART_DATA.$teamId.'_'.now()->subDay()->format('Y-m-d'));
        }
    }
}

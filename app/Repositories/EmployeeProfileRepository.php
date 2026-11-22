<?php

namespace App\Repositories;

use App\Constants\CacheConstants;
use App\DTOs\EmergencyContactDto;
use App\DTOs\EmployeeProfileDto;
use App\Interfaces\BankInformationRepositoryInterface;
use App\Interfaces\EmergencyContactRepositoryInterface;
use App\Interfaces\EmployeeProfileRepositoryInterface;
use App\Interfaces\JobInformationRepositoryInterface;
use App\Interfaces\UserRepositoryInterface;
use App\Models\BankInformation;
use App\Models\EmergencyContact;
use App\Models\EmployeeEducation;
use App\Models\EmployeeProfile;
use App\Models\JobInformation;
use App\Models\Team;
use App\Models\TeamMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EmployeeProfileRepository implements EmployeeProfileRepositoryInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private JobInformationRepositoryInterface $jobInformationRepository,
        private BankInformationRepositoryInterface $bankInformationRepository,
        private EmergencyContactRepositoryInterface $emergencyContactRepository
    ) {}

    public function getAll(
        ?string $search,
        ?string $status,
        ?string $type,
        ?string $workLocation,
        ?string $projectId,
        ?int $limit,
        bool $execute,
        ?string $department = null,
        ?string $storeId = null,
        ?string $vendorName = null
    ): Builder|Collection {
        // If search is provided, use Scout for full-text search
        if ($search) {
            // Get IDs from Scout search first
            $scoutQuery = EmployeeProfile::search($search);

            // Get the IDs from search results
            $searchResults = $scoutQuery->keys();

            // Build query with IDs and eager loading
            $query = EmployeeProfile::with(['user.roles', 'jobInformation.store', 'bankInformation', 'emergencyContacts'])
                ->whereIn('id', $searchResults);
        } else {
            // For non-search queries, use regular Eloquent
            $query = EmployeeProfile::with(['user.roles', 'jobInformation.store', 'bankInformation', 'emergencyContacts']);
        }

        // Apply filters (extracted to avoid duplication)
        $this->applyFilters($query, $status, $type, $workLocation, $projectId, $department, $storeId, $vendorName);

        $query->orderByDesc('created_at');

        if ($limit) {
            $query->take($limit);
        }

        if ($execute) {
            return $query->get();
        }

        return $query;
    }

    /**
     * Apply common filters to employee query
     */
    private function applyFilters(
        $query,
        ?string $status,
        ?string $type,
        ?string $workLocation,
        ?string $projectId,
        ?string $department = null,
        ?string $storeId = null,
        ?string $vendorName = null
    ): void {
        $authUser = auth()->user();
        if ($authUser && $authUser->isStoreHead()) {
            $managedStoreIds = $authUser->managedStoreIds();
            $query->whereHas('jobInformation', function ($q) use ($managedStoreIds) {
                $q->whereIn('store_id', $managedStoreIds);
            });
        }

        if ($status) {
            $query->whereHas('jobInformation', function ($q) use ($status) {
                $q->where('status', $status);
            });
        }

        if ($type) {
            $query->whereHas('jobInformation', function ($q) use ($type) {
                $q->where('employment_type', $type);
            });
        }

        if ($workLocation) {
            $query->whereHas('jobInformation', function ($q) use ($workLocation) {
                $q->where('work_location', $workLocation);
            });
        }

        if ($department) {
            $query->where(function ($q) use ($department) {
                $q->whereHas('jobInformation', fn ($jq) => $jq->where('department', $department))
                    ->orWhereHas('jobInformation.team', fn ($tq) => $tq->where('department', $department));
            });
        }

        if ($storeId) {
            $query->whereHas('jobInformation', function ($q) use ($storeId) {
                $q->where('store_id', $storeId);
            });
        }

        if ($vendorName) {
            $query->whereHas('jobInformation', function ($q) use ($vendorName) {
                $q->where('vendor_name', 'like', '%'.$vendorName.'%');
            });
        }

        if ($projectId) {
            $query->where(function ($q) use ($projectId) {
                $q->whereHas('ledProjects', function ($leaderQ) use ($projectId) {
                    $leaderQ->where('projects.id', $projectId);
                });

                $q->orWhereHas('teamMembers', function ($tmQ) use ($projectId) {
                    $tmQ->whereNull('left_at')
                        ->whereHas('team.projects', function ($projQ) use ($projectId) {
                            $projQ->where('projects.id', $projectId);
                        });
                });
            });
        }
    }

    public function getAllPaginated(
        ?string $search,
        ?string $status,
        ?string $type,
        ?string $workLocation,
        ?string $projectId,
        int $rowPerPage,
        ?string $department = null,
        ?string $storeId = null,
        ?string $vendorName = null
    ): LengthAwarePaginator {
        $query = $this->getAll(
            $search,
            $status,
            $type,
            $workLocation,
            $projectId,
            null,
            false,
            $department,
            $storeId,
            $vendorName
        );

        // Use regular pagination for all cases to ensure consistency
        return $query->paginate($rowPerPage);
    }

    public function getById(
        string $id
    ): EmployeeProfile {
        $employee = EmployeeProfile::with([
            'user',
            'user.roles',
            'jobInformation.store',
            'jobInformation.team.leader',
            'jobInformation.team' => function ($query) {
                $query->withCount('members');
            },
            'bankInformation',
            'emergencyContacts',
            'educations',
        ])
            ->findOrFail($id);

        $authUser = auth()->user();
        if ($authUser && $authUser->isStoreHead()) {
            $managedStoreIds = $authUser->managedStoreIds();
            $empStoreId = $employee->jobInformation?->store_id;
            if ($empStoreId === null || ! in_array((int) $empStoreId, $managedStoreIds, true)) {
                throw new HttpException(403, 'You do not have permission to view employees outside your store.');
            }
        }

        return $employee;
    }

    public function getMyProfile(): EmployeeProfile
    {
        $userId = auth()->user()->id;

        return EmployeeProfile::with([
            'user',
            'jobInformation.team.leader',
            'jobInformation.team' => function ($query) {
                $query->withCount('members');
            },
            'bankInformation',
            'emergencyContacts',
            'educations',
        ])
            ->where('user_id', $userId)
            ->firstOrFail();
    }

    public function create(array $data): EmployeeProfile
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return DB::transaction(function () use ($data) {
                    $user = $this->createUser($data);
                    $employee = $this->createEmployeeProfile($data, $user->id);

                    $this->createJobInformation($data, $employee->id);
                    $this->createBankInformation($data, $employee->id);
                    $this->createEmergencyContacts($data, $employee->id);
                    $this->createEducations($data, $employee->id);
                    $this->manageTeamMembership($employee->id, $data['team_id'] ?? null);

                    // Clear statistics cache
                    $this->clearEmployeeStatisticsCache();

                    return $employee->load(['user', 'jobInformation.store', 'bankInformation', 'emergencyContacts', 'educations']);
                });
            } catch (QueryException $e) {
                if ($attempt < $maxAttempts && $this->isEmployeeCodeUniqueViolation($e)) {
                    continue;
                }

                throw $e;
            }
        }

        throw new \RuntimeException('Failed to generate unique employee code after multiple attempts.');
    }

    public function update(string $id, array $data): EmployeeProfile
    {
        return DB::transaction(function () use ($id, $data) {
            $employee = $this->getById($id);

            $this->updateUser($employee->user_id, $data);

            $employeeDto = EmployeeProfileDto::fromArrayForUpdate($data, $employee);
            $employee->update($employeeDto->toArray());

            $this->updateJobInformation($employee->id, $data);
            $this->updateBankInformation($employee->id, $data);
            $this->updateEmergencyContacts($employee->id, $data);
            $this->updateEducations($employee->id, $data);
            $this->manageTeamMembership($employee->id, $data['team_id'] ?? null);

            // Clear statistics cache
            $this->clearEmployeeStatisticsCache();

            return $employee->fresh(['user', 'user.roles', 'jobInformation.store', 'jobInformation.team', 'bankInformation', 'emergencyContacts', 'educations']);
        });
    }

    public function delete(string $id): EmployeeProfile
    {
        return DB::transaction(function () use ($id) {
            $employee = $this->getById($id);

            if ($employee->user && $employee->user->hasRole('super-admin')) {
                throw new HttpException(403, 'Super Admin user cannot be deleted.');
            }

            $employee->delete();

            // Clear statistics cache
            $this->clearEmployeeStatisticsCache();

            return $employee;
        });
    }

    private function createUser(array $data)
    {
        $userData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'roles' => $data['roles'] ?? [],
        ];

        if (isset($data['profile_photo'])) {
            $userData['profile_photo'] = $data['profile_photo'];
        }

        return $this->userRepository->create($userData);
    }

    private function createEmployeeProfile(array $data, int $userId): EmployeeProfile
    {
        $employeeCode = $this->generateEmployeeCode();

        $employeeData = array_merge($data, [
            'user_id' => $userId,
            'code' => $employeeCode,
        ]);

        $employeeDto = EmployeeProfileDto::fromArray($employeeData);
        $employeeArray = $employeeDto->toArray();

        return EmployeeProfile::create($employeeArray);
    }

    private function createJobInformation(array $data, int $employeeId): void
    {
        $jobData = [
            'employee_id' => $employeeId,
            'job_title' => $data['job_title'] ?? null,
            'team_id' => $data['team_id'] ?? null,
            'department' => $data['department'] ?? null,
            'store_id' => $data['store_id'] ?? null,
            'years_experience' => $data['years_experience'] ?? null,
            'status' => $data['status'] ?? null,
            'employment_type' => $data['employment_type'] ?? null,
            'vendor_name' => ($data['employment_type'] ?? null) === 'vendor' ? ($data['vendor_name'] ?? null) : null,
            'work_location' => $data['work_location'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'monthly_salary' => $data['monthly_salary'] ?? null,
            'skill_level' => $data['skill_level'] ?? null,
        ];

        $this->jobInformationRepository->create($jobData);
    }

    private function createBankInformation(array $data, int $employeeId): void
    {
        $bankData = [
            'employee_id' => $employeeId,
            'bank_name' => $data['bank_name'],
            'account_number' => $data['account_number'],
            'account_holder_name' => $data['account_holder_name'],
            'bank_branch' => $data['bank_branch'] ?? null,
            'account_type' => $data['account_type'] ?? null,
        ];

        $this->bankInformationRepository->create($bankData);
    }

    private function createEmergencyContacts(array $data, int $employeeId): void
    {
        foreach ($data['emergency_contacts'] as $contactData) {
            $contactData['employee_id'] = $employeeId;
            $emergencyContactDto = EmergencyContactDto::fromArray($contactData);

            $this->emergencyContactRepository->create($emergencyContactDto->toArray());
        }
    }

    private function createEducations(array $data, int $employeeId): void
    {
        $educations = $data['educations'] ?? [];
        if (! is_array($educations)) {
            return;
        }

        foreach ($educations as $edu) {
            EmployeeEducation::create([
                'employee_id' => $employeeId,
                'level' => $edu['level'],
                'institution' => $edu['institution'],
                'major' => $edu['major'] ?? null,
                'start_year' => (int) $edu['start_year'],
                'end_year' => isset($edu['end_year']) && $edu['end_year'] !== '' ? (int) $edu['end_year'] : null,
                'gpa' => isset($edu['gpa']) && $edu['gpa'] !== '' ? (float) $edu['gpa'] : null,
                'is_highest' => ! empty($edu['is_highest']),
            ]);
        }
    }

    private function updateEducations(int $employeeId, array $data): void
    {
        $educations = $data['educations'] ?? null;
        if (! is_array($educations) || empty($educations)) {
            return;
        }

        $existingIds = EmployeeEducation::where('employee_id', $employeeId)
            ->pluck('id')
            ->toArray();

        $keptIds = [];

        foreach ($educations as $edu) {
            $payload = [
                'employee_id' => $employeeId,
                'level' => $edu['level'],
                'institution' => $edu['institution'],
                'major' => $edu['major'] ?? null,
                'start_year' => (int) $edu['start_year'],
                'end_year' => isset($edu['end_year']) && $edu['end_year'] !== '' ? (int) $edu['end_year'] : null,
                'gpa' => isset($edu['gpa']) && $edu['gpa'] !== '' ? (float) $edu['gpa'] : null,
                'is_highest' => ! empty($edu['is_highest']),
            ];

            if (! empty($edu['id'])) {
                $existing = EmployeeEducation::where('employee_id', $employeeId)
                    ->where('id', $edu['id'])
                    ->first();

                if ($existing) {
                    $existing->update($payload);
                    $keptIds[] = $existing->id;
                    continue;
                }
            }

            $created = EmployeeEducation::create($payload);
            $keptIds[] = $created->id;
        }

        $toDelete = array_diff($existingIds, $keptIds);
        if (! empty($toDelete)) {
            EmployeeEducation::where('employee_id', $employeeId)
                ->whereIn('id', $toDelete)
                ->delete();
        }
    }

    private function generateEmployeeCode(): string
    {
        $year = now()->format('Y');
        $month = now()->format('m');
        $prefix = "EMP{$year}{$month}";

        $lastEmployee = EmployeeProfile::withTrashed()
            ->where('code', 'like', "{$prefix}%")
            ->orderByDesc('code')
            ->lockForUpdate()
            ->first();

        $lastSequence = $lastEmployee ? (int) substr($lastEmployee->code, -4) : 0;

        return sprintf('%s%04d', $prefix, $lastSequence + 1);
    }

    private function isEmployeeCodeUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        if ($sqlState !== '23000') {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'employee_profiles_code_unique')
            || str_contains($message, 'employee_profiles.code');
    }

    private function updateUser(int $userId, array $data): void
    {
        $fields = ['name', 'email', 'password', 'roles', 'profile_photo'];
        $userData = array_intersect_key($data, array_flip($fields));

        if ($userData) {
            $this->userRepository->update($userId, $userData);
        }
    }

    private function updateJobInformation(int $employeeId, array $data): void
    {
        $fields = [
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

        $jobData = array_intersect_key($data, array_flip($fields));

        if (empty($jobData)) {
            return;
        }

        $jobInfo = JobInformation::where('employee_id', $employeeId)->first();

        if ($jobInfo) {
            $this->jobInformationRepository->update($jobInfo->id, $jobData);
        } else {
            $this->createJobInformation($data, $employeeId);
        }
    }

    private function updateBankInformation(int $employeeId, array $data): void
    {
        $fields = [
            'bank_name',
            'account_number',
            'account_holder_name',
            'bank_branch',
            'account_type',
        ];

        $bankData = array_intersect_key($data, array_flip($fields));

        if (empty($bankData)) {
            return;
        }

        $bankInfo = BankInformation::where('employee_id', $employeeId)->first();

        if ($bankInfo) {
            $this->bankInformationRepository->update($bankInfo->id, $bankData);
        } else {
            $this->createBankInformation($data, $employeeId);
        }
    }

    private function updateEmergencyContacts(int $employeeId, array $data): void
    {
        $contacts = $data['emergency_contacts'] ?? null;
        if (! is_array($contacts) || empty($contacts)) {
            return;
        }

        $existingContactIds = EmergencyContact::where('employee_id', $employeeId)
            ->pluck('id')
            ->toArray();

        $submittedContactIds = [];

        foreach ($contacts as $contact) {
            $contact['employee_id'] = $employeeId;

            if (isset($contact['id']) && $contact['id']) {
                $existingContact = EmergencyContact::find($contact['id']);
                if ($existingContact) {
                    $emergencyContactDto = EmergencyContactDto::fromArrayForUpdate($contact, $existingContact);
                    $this->emergencyContactRepository->update($contact['id'], $emergencyContactDto->toArray());
                    $submittedContactIds[] = $contact['id'];
                }
            } else {
                $emergencyContactDto = EmergencyContactDto::fromArray($contact);
                $newContact = $this->emergencyContactRepository->create($emergencyContactDto->toArray());
                $submittedContactIds[] = $newContact->id;
            }
        }

        $contactsToDelete = array_diff($existingContactIds, $submittedContactIds);
        if (! empty($contactsToDelete)) {
            EmergencyContact::whereIn('id', $contactsToDelete)->delete();
        }
    }

    private function manageTeamMembership(int $employeeId, ?int $teamId): void
    {
        $currentMembership = TeamMember::where('employee_id', $employeeId)
            ->whereNull('left_at')
            ->first();

        $currentTeamId = $currentMembership?->team_id;

        if ($teamId === null) {
            if ($currentMembership) {
                $currentMembership->update(['left_at' => now()]);
            }

            return;
        }

        if ($currentTeamId === $teamId) {
            return;
        }

        if ($currentMembership) {
            $currentMembership->update(['left_at' => now()]);
        }

        TeamMember::updateOrCreate(
            [
                'team_id' => $teamId,
                'employee_id' => $employeeId,
            ],
            [
                'joined_at' => now(),
                'left_at' => null,
            ]
        );
    }

    public function getStatistics(?int $storeId = null): array
    {
        $authUser = auth()->user();
        $managedStoreIds = ($authUser && $authUser->isStoreHead()) ? $authUser->managedStoreIds() : null;

        $currentMonth = now()->month;
        $currentYear = now()->year;
        $lastWeekEnd = now()->subWeek()->endOfWeek();

        $query = DB::table('employee_profiles')
            ->join('users', 'users.id', '=', 'employee_profiles.user_id')
            ->join('job_information', 'job_information.employee_id', '=', 'employee_profiles.id')
            ->whereNull('employee_profiles.deleted_at')
            ->whereNull('users.deleted_at')
            ->whereNull('job_information.deleted_at')
            ->where('users.is_active', true)
            ->whereIn('job_information.status', ['active', 'on_leave'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', \App\Models\User::class)
                    ->where('roles.name', 'super-admin');
            });

        if ($managedStoreIds !== null) {
            $query->whereIn('job_information.store_id', $managedStoreIds);
        } elseif ($storeId !== null) {
            $query->where('job_information.store_id', $storeId);
        }

        $rows = $query->get([
            'employee_profiles.id',
            'employee_profiles.created_at',
            'job_information.status',
            'job_information.employment_type',
            'job_information.monthly_salary',
            'job_information.start_date',
        ]);

        $total = $rows->count();
        $vendorCount = 0;
        $internalCount = 0;
        $activeCount = 0;
        $activeLastWeek = 0;
        $onLeaveCount = 0;
        $onLeaveLastWeek = 0;
        $newHiresCount = 0;
        $salarySum = 0.0;
        $salaryCount = 0;

        foreach ($rows as $row) {
            $empType = strtolower((string) ($row->employment_type ?? ''));
            $isVendor = $empType === 'vendor';
            if ($isVendor) {
                $vendorCount++;
            } else {
                $internalCount++;
                $salary = (float) ($row->monthly_salary ?? 0);
                if ($salary > 0) {
                    $salarySum += $salary;
                    $salaryCount++;
                }
            }

            $status = strtolower((string) ($row->status ?? ''));
            $createdAt = $row->created_at ? \Carbon\Carbon::parse($row->created_at) : null;
            if ($status === 'active') {
                $activeCount++;
                if ($createdAt && $createdAt->lte($lastWeekEnd)) {
                    $activeLastWeek++;
                }
            } elseif ($status === 'on_leave') {
                $onLeaveCount++;
                if ($createdAt && $createdAt->lte($lastWeekEnd)) {
                    $onLeaveLastWeek++;
                }
            }

            if ($row->start_date) {
                $startDate = \Carbon\Carbon::parse($row->start_date);
                if ($startDate->month === $currentMonth && $startDate->year === $currentYear) {
                    $newHiresCount++;
                }
            }
        }

        $averageSalary = $salaryCount > 0 ? round($salarySum / $salaryCount, 2) : 0.0;

        return [
            'total' => $total,
            'internal_count' => $internalCount,
            'vendor_count' => $vendorCount,
            'added_this_month' => $newHiresCount,
            'new_employees' => $newHiresCount,
            'period_label' => 'This month: '.now()->format('M Y'),
            'active' => $activeCount,
            'active_change' => $activeCount - $activeLastWeek,
            'on_leave' => $onLeaveCount,
            'on_leave_change' => $onLeaveCount - $onLeaveLastWeek,
            'average_salary' => $averageSalary,
        ];
    }

    /**
     * Clear employee statistics cache
     */
    private function clearEmployeeStatisticsCache(): void
    {
        cache()->forget(CacheConstants::CACHE_KEY_EMPLOYEE_STATISTICS.now()->format('Y-m-d-H'));
        cache()->forget(CacheConstants::CACHE_KEY_EMPLOYEE_TOTAL_COUNT);
    }

    public function getPerformanceStatistics(string $employeeId): array
    {
        $employee = EmployeeProfile::findOrFail($employeeId);

        // Tasks Completed this month (from project_tasks)
        $tasksCompletedThisMonth = DB::table('project_tasks')
            ->where('assignee_id', $employeeId)
            ->where('status', 'done')
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->count();

        // Attendance Rate (percentage of days attended this month)
        $workingDaysThisMonth = now()->diffInDaysFiltered(function ($date) {
            return ! $date->isWeekend();
        }, now()->startOfMonth());

        $attendanceCount = DB::table('attendances')
            ->where('employee_id', $employeeId)
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->count();

        $attendanceRate = $workingDaysThisMonth > 0
            ? round(($attendanceCount / $workingDaysThisMonth) * 100, 1)
            : 0;

        // Projects count (active projects assigned to this employee via teams)
        $projectsCount = DB::table('team_members')
            ->join('teams', 'team_members.team_id', '=', 'teams.id')
            ->join('project_teams', 'teams.id', '=', 'project_teams.team_id')
            ->join('projects', 'project_teams.project_id', '=', 'projects.id')
            ->where('team_members.employee_id', $employeeId)
            ->where('projects.status', 'active')
            ->distinct()
            ->count('projects.id');

        // Performance Score (average based on tasks completion rate and attendance)
        // Simple calculation: (task completion rate + attendance rate) / 2
        $totalTasksThisMonth = DB::table('project_tasks')
            ->where('assignee_id', $employeeId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $taskCompletionRate = $totalTasksThisMonth > 0
            ? round(($tasksCompletedThisMonth / $totalTasksThisMonth) * 100, 1)
            : 0;

        $performanceScore = round(($taskCompletionRate + $attendanceRate) / 2, 1);

        return [
            'tasks_completed' => $tasksCompletedThisMonth,
            'attendance_rate' => $attendanceRate,
            'projects_count' => $projectsCount,
            'performance_score' => $performanceScore,
        ];
    }

    public function getMyTeam(): Team
    {
        $userId = auth()->user()->id;

        $employee = EmployeeProfile::with([
            'jobInformation.team.leader',
        ])
            ->where('user_id', $userId)
            ->firstOrFail();

        if (! $employee->jobInformation || ! $employee->jobInformation->team) {
            throw new \Exception('You are not assigned to any team');
        }

        $team = $employee->jobInformation->team;
        $team->loadCount('members');

        return $team;
    }

    public function getMyTeamMembers(): Collection
    {
        $userId = auth()->user()->id;

        $employee = EmployeeProfile::with('jobInformation.team')
            ->where('user_id', $userId)
            ->firstOrFail();

        if (! $employee->jobInformation || ! $employee->jobInformation->team) {
            throw new \Exception('You are not assigned to any team');
        }

        return $employee->jobInformation->team
            ->members()
            ->with([
                'employee.user',
                'employee.jobInformation',
            ])
            ->orderBy('joined_at', 'desc')
            ->get();
    }

    public function getMyTeamProjects(): Collection
    {
        $userId = auth()->user()->id;

        $employee = EmployeeProfile::with('jobInformation.team')
            ->where('user_id', $userId)
            ->firstOrFail();

        if (! $employee->jobInformation || ! $employee->jobInformation->team) {
            throw new \Exception('You are not assigned to any team');
        }

        return $employee->jobInformation->team
            ->projects()
            ->with(['teams', 'projectLeader.user', 'projectLeader.jobInformation', 'tasks'])
            ->orderBy('created_at', 'desc')
            ->get();
    }
}

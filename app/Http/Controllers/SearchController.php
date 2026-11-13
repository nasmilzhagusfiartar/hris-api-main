<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Attendance;
use App\Models\EmployeeProfile;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SearchController extends Controller
{
    /**
     * Global search across HRIS modules filtered by user role and permissions.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
        ]);

        $keyword = trim($validated['q']);
        $department = ! empty($validated['department']) && $validated['department'] !== 'All Departments'
            ? strtolower(trim($validated['department']))
            : null;
        $status = ! empty($validated['status']) && $validated['status'] !== 'All Status'
            ? strtolower(trim($validated['status']))
            : null;

        $user = $request->user();
        $isEmployeeOnly = $user->hasRole('employee') && ! $user->hasAnyRole(['manager', 'admin', 'hr', 'finance']);
        $employeeProfileId = $user->employeeProfile?->id;

        $results = [];

        // 1. Employees (Manager, Admin, HR, or users with employee-list permission)
        if (! $isEmployeeOnly && ($user->hasAnyRole(['manager', 'admin', 'hr']) || $user->can('employee-list'))) {
            $employeesQuery = EmployeeProfile::with(['user', 'jobInformation.team'])
                ->where(function ($q) use ($keyword) {
                    $q->where('code', 'like', "%{$keyword}%")
                        ->orWhere('phone', 'like', "%{$keyword}%")
                        ->orWhereHas('user', function ($uq) use ($keyword) {
                            $uq->where('name', 'like', "%{$keyword}%")
                                ->orWhere('email', 'like', "%{$keyword}%");
                        })
                        ->orWhereHas('jobInformation', function ($jq) use ($keyword) {
                            $jq->where('job_title', 'like', "%{$keyword}%");
                        });
                });

            if ($department) {
                $employeesQuery->whereHas('jobInformation.team', function ($tq) use ($department) {
                    $tq->whereRaw('LOWER(department) = ?', [$department]);
                });
            }

            if ($status) {
                $employeesQuery->whereHas('jobInformation', function ($jq) use ($status) {
                    $jq->whereRaw('LOWER(status) = ?', [$status]);
                });
            }

            $employees = $employeesQuery->limit(10)->get();

            foreach ($employees as $emp) {
                $photo = $emp->user?->profile_photo;
                $results[] = [
                    'category' => 'Employees',
                    'id' => $emp->id,
                    'title' => $emp->user?->name ?? $emp->code,
                    'subtitle' => trim(($emp->jobInformation?->job_title ?? 'Employee').' • '.($emp->code ?? '')),
                    'avatar' => $photo ? Storage::disk('public')->url($photo) : null,
                    'route' => [
                        'name' => 'admin.employees.detail',
                        'params' => ['id' => $emp->id],
                    ],
                ];
            }
        }

        // 2. Teams & Departments
        if (! $isEmployeeOnly && ($user->hasAnyRole(['manager', 'admin', 'hr']) || $user->can('team-list'))) {
            $teamsQuery = Team::with('leader')
                ->where(function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%")
                        ->orWhere('department', 'like', "%{$keyword}%")
                        ->orWhere('description', 'like', "%{$keyword}%");
                });

            if ($department) {
                $teamsQuery->whereRaw('LOWER(department) = ?', [$department]);
            }

            if ($status) {
                $teamsQuery->whereRaw('LOWER(status) = ?', [$status]);
            }

            $teams = $teamsQuery->limit(10)->get();

            foreach ($teams as $team) {
                $results[] = [
                    'category' => 'Teams & Departments',
                    'id' => $team->id,
                    'title' => $team->name,
                    'subtitle' => ucfirst((string) $team->department).' • '.ucfirst((string) $team->status),
                    'avatar' => $team->icon ? Storage::disk('public')->url($team->icon) : null,
                    'route' => [
                        'name' => 'admin.team.detail',
                        'params' => ['id' => $team->id],
                    ],
                ];
            }
        } elseif ($isEmployeeOnly && $employeeProfileId) {
            $myTeams = Team::whereHas('members', function ($mq) use ($employeeProfileId) {
                $mq->where('employee_id', $employeeProfileId)->whereNull('left_at');
            })
                ->where(function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%")
                        ->orWhere('department', 'like', "%{$keyword}%");
                })
                ->limit(10)
                ->get();

            foreach ($myTeams as $team) {
                $results[] = [
                    'category' => 'Teams & Departments',
                    'id' => $team->id,
                    'title' => $team->name,
                    'subtitle' => ucfirst((string) $team->department).' • My Team',
                    'avatar' => $team->icon ? Storage::disk('public')->url($team->icon) : null,
                    'route' => [
                        'name' => 'employee.team',
                    ],
                ];
            }
        }

        // 3. Projects
        if ($user->hasAnyRole(['manager', 'admin', 'hr']) || $user->can('project-list') || $isEmployeeOnly) {
            $projectsQuery = Project::where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%")
                    ->orWhere('type', 'like', "%{$keyword}%");
            });

            if ($status) {
                $projectsQuery->whereRaw('LOWER(status) = ?', [$status]);
            }

            if ($isEmployeeOnly && $employeeProfileId) {
                $projectsQuery->where(function ($pq) use ($employeeProfileId) {
                    $pq->where('project_leader_id', $employeeProfileId)
                        ->orWhereHas('teams.members', function ($mq) use ($employeeProfileId) {
                            $mq->where('employee_id', $employeeProfileId)->whereNull('left_at');
                        });
                });
            }

            $projects = $projectsQuery->limit(10)->get();

            foreach ($projects as $project) {
                $results[] = [
                    'category' => 'Projects',
                    'id' => $project->id,
                    'title' => $project->name,
                    'subtitle' => ucfirst((string) $project->priority).' Priority • '.ucfirst((string) $project->status),
                    'avatar' => $project->photo ? Storage::disk('public')->url($project->photo) : null,
                    'route' => ! $isEmployeeOnly
                        ? ['name' => 'admin.projects.detail', 'params' => ['id' => $project->id]]
                        : ['name' => 'employee.team'],
                ];
            }
        }

        // 4. Tasks
        $tasksQuery = ProjectTask::with('project')
            ->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%");
            });

        if ($status) {
            $tasksQuery->whereRaw('LOWER(status) = ?', [$status]);
        }

        if ($isEmployeeOnly && $employeeProfileId) {
            $tasksQuery->where('assignee_id', $employeeProfileId);
        }

        $tasks = $tasksQuery->limit(10)->get();
        foreach ($tasks as $task) {
            $results[] = [
                'category' => 'Tasks',
                'id' => $task->id,
                'title' => $task->name,
                'subtitle' => ($task->project?->name ?? 'Project').' • '.ucfirst(str_replace('_', ' ', (string) $task->status)),
                'route' => ! $isEmployeeOnly && $task->project_id
                    ? ['name' => 'admin.projects.detail', 'params' => ['id' => $task->project_id]]
                    : ['name' => 'employee.team'],
            ];
        }

        // 5. Leave Requests
        if (! $isEmployeeOnly && ($user->hasAnyRole(['manager', 'admin', 'hr']) || $user->can('leave-request-list') || $user->can('attendance-list'))) {
            $leavesQuery = LeaveRequest::with('employee.user')
                ->where(function ($q) use ($keyword) {
                    $q->where('leave_type', 'like', "%{$keyword}%")
                        ->orWhere('reason', 'like', "%{$keyword}%")
                        ->orWhere('status', 'like', "%{$keyword}%")
                        ->orWhereHas('employee.user', function ($uq) use ($keyword) {
                            $uq->where('name', 'like', "%{$keyword}%");
                        });
                });

            if ($status) {
                $leavesQuery->whereRaw('LOWER(status) = ?', [$status]);
            }

            $leaves = $leavesQuery->orderByDesc('created_at')->limit(10)->get();
            foreach ($leaves as $leave) {
                $results[] = [
                    'category' => 'Leave Requests',
                    'id' => $leave->id,
                    'title' => ($leave->employee?->user?->name ?? 'Employee').' — '.ucfirst((string) $leave->leave_type),
                    'subtitle' => ($leave->start_date?->format('d M Y') ?? '').' • '.ucfirst((string) $leave->status),
                    'route' => ['name' => 'admin.attendances'],
                ];
            }
        } elseif ($isEmployeeOnly && $employeeProfileId) {
            $myLeaves = LeaveRequest::where('employee_id', $employeeProfileId)
                ->where(function ($q) use ($keyword) {
                    $q->where('leave_type', 'like', "%{$keyword}%")
                        ->orWhere('reason', 'like', "%{$keyword}%")
                        ->orWhere('status', 'like', "%{$keyword}%");
                })
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();

            foreach ($myLeaves as $leave) {
                $results[] = [
                    'category' => 'Leave Requests',
                    'id' => $leave->id,
                    'title' => ucfirst((string) $leave->leave_type).' Leave',
                    'subtitle' => ($leave->start_date?->format('d M Y') ?? '').' • '.ucfirst((string) $leave->status),
                    'route' => ['name' => 'employee.attendance.my-attendances'],
                ];
            }
        }

        // 6. Attendance
        if (! $isEmployeeOnly && ($user->hasAnyRole(['manager', 'admin', 'hr']) || $user->can('attendance-list'))) {
            $attQuery = Attendance::with('employee.user')
                ->where(function ($q) use ($keyword) {
                    $q->where('status', 'like', "%{$keyword}%")
                        ->orWhere('notes', 'like', "%{$keyword}%")
                        ->orWhereHas('employee.user', function ($uq) use ($keyword) {
                            $uq->where('name', 'like', "%{$keyword}%");
                        });
                })
                ->orderByDesc('date')
                ->limit(10)
                ->get();

            foreach ($attQuery as $att) {
                $results[] = [
                    'category' => 'Attendance',
                    'id' => $att->id,
                    'title' => ($att->employee?->user?->name ?? 'Employee').' — '.ucfirst((string) $att->status),
                    'subtitle' => $att->date?->format('d M Y') ?? '',
                    'route' => ['name' => 'admin.attendances'],
                ];
            }
        } elseif ($isEmployeeOnly && $employeeProfileId) {
            $myAtt = Attendance::where('employee_id', $employeeProfileId)
                ->where(function ($q) use ($keyword) {
                    $q->where('status', 'like', "%{$keyword}%")
                        ->orWhere('notes', 'like', "%{$keyword}%");
                })
                ->orderByDesc('date')
                ->limit(10)
                ->get();

            foreach ($myAtt as $att) {
                $results[] = [
                    'category' => 'Attendance',
                    'id' => $att->id,
                    'title' => 'Attendance: '.ucfirst((string) $att->status),
                    'subtitle' => $att->date?->format('d M Y') ?? '',
                    'route' => ['name' => 'employee.attendance.my-attendances'],
                ];
            }
        }

        $grouped = collect($results)->groupBy('category')->toArray();

        return ResponseHelper::jsonResponse(true, 'Search Results Retrieved Successfully', [
            'query' => $keyword,
            'total' => count($results),
            'items' => $results,
            'grouped' => $grouped,
        ], 200);
    }
}


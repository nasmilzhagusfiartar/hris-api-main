<?php

namespace App\Repositories;

use App\DTOs\LeaveRequestDto;
use App\Interfaces\LeaveRequestRepositoryInterface;
use App\Models\LeaveRequest;
use App\Services\EmailService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LeaveRequestRepository implements LeaveRequestRepositoryInterface
{
    public function __construct(
        private EmailService $emailService
    ) {}

    public function getAll(
        ?string $search,
        ?int $limit,
        bool $execute
    ) {
        $authUser = Auth::user();
        $managedStoreIds = ($authUser && $authUser->isStoreHead()) ? $authUser->managedStoreIds() : null;

        $query = LeaveRequest::with(['employee.user', 'approver.user'])
            ->whereDoesntHave('employee.jobInformation', function ($jq) {
                $jq->where('employment_type', 'vendor');
            })
            ->when($managedStoreIds !== null, function ($q) use ($managedStoreIds) {
                $q->whereHas('employee.jobInformation', function ($jq) use ($managedStoreIds) {
                    $jq->whereIn('store_id', $managedStoreIds);
                });
            })
            ->where(function ($query) use ($search) {
                if ($search) {
                    $query->search($search);
                }
            })
            ->orderBy('created_at', 'desc');

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
        int $rowPerPage
    ) {
        $query = $this->getAll(
            $search,
            null,
            false
        );

        return $query->paginate($rowPerPage);
    }

    public function getById(
        string $id
    ) {
        return LeaveRequest::with(['employee.user', 'approver.user'])
            ->findOrFail($id);
    }

    public function getMyLeaveRequests()
    {
        return LeaveRequest::with(['employee.user', 'approver.user'])
            ->where('employee_id', Auth::user()->employeeProfile->id)
            ->whereDate('created_at', '>=', now()->subDays(6)->startOfDay())
            ->whereDate('created_at', '<=', now()->endOfDay())
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function store(array $data)
    {
        return DB::transaction(function () use ($data) {
            $startDate = Carbon::parse($data['start_date']);
            $endDate = Carbon::parse($data['end_date']);

            $data['total_days'] = $startDate->diffInDays($endDate) + 1;

            $leaveRequestDto = LeaveRequestDto::fromArray($data);
            $leaveRequest = LeaveRequest::create($leaveRequestDto->toArray());

            DB::afterCommit(function () use ($leaveRequest) {
                $this->emailService->sendLeaveRequestCreatedNotification($leaveRequest);
            });

            return $leaveRequest;
        });
    }

    public function approve(string $id)
    {
        return DB::transaction(function () use ($id) {
            $leaveRequest = $this->getById($id);
            $this->assertCanManageLeaveRequest($leaveRequest);

            $data = [
                'status' => 'approved',
                'approved_by' => Auth::user()->employeeProfile?->id,
            ];

            $leaveRequestDto = LeaveRequestDto::fromArrayForUpdate($data, $leaveRequest);
            $leaveRequest->update($leaveRequestDto->toArray());

            DB::afterCommit(function () use ($leaveRequest) {
                $this->emailService->sendLeaveRequestApprovedNotification($leaveRequest);
            });

            return $leaveRequest;
        });
    }

    public function reject(string $id)
    {
        return DB::transaction(function () use ($id) {
            $leaveRequest = $this->getById($id);
            $this->assertCanManageLeaveRequest($leaveRequest);

            $data = [
                'status' => 'rejected',
                'approved_by' => Auth::user()->employeeProfile?->id,
            ];

            $leaveRequestDto = LeaveRequestDto::fromArrayForUpdate($data, $leaveRequest);
            $leaveRequest->update($leaveRequestDto->toArray());

            DB::afterCommit(function () use ($leaveRequest) {
                $this->emailService->sendLeaveRequestRejectedNotification($leaveRequest);
            });

            return $leaveRequest;
        });
    }

    private function assertCanManageLeaveRequest(LeaveRequest $leaveRequest): void
    {
        $authUser = Auth::user();
        if (! $authUser || ! $authUser->isStoreHead()) {
            return;
        }

        $managedStoreIds = $authUser->managedStoreIds();
        $employeeStoreId = $leaveRequest->employee?->jobInformation?->store_id;

        if ($employeeStoreId === null || ! in_array((int) $employeeStoreId, $managedStoreIds, true)) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(
                403,
                'You can only approve or reject leave requests for employees in your store.'
            );
        }
    }
}

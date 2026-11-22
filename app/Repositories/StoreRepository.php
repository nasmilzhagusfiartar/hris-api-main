<?php

namespace App\Repositories;

use App\Interfaces\StoreRepositoryInterface;
use App\Models\Store;
use App\Models\Team;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StoreRepository implements StoreRepositoryInterface
{
    public function getAll(
        ?string $search,
        ?string $brand,
        ?string $city,
        ?bool $isActive,
        ?int $limit,
        bool $execute
    ): Builder|Collection {
        $authUser = auth()->user();
        $managedStoreIds = ($authUser && $authUser->isStoreHead()) ? $authUser->managedStoreIds() : null;

        $query = Store::query()
            ->when($managedStoreIds !== null, fn (Builder $q) => $q->whereIn('id', $managedStoreIds))
            ->with(['head', 'teams' => fn ($q) => $q->withCount('members')->orderBy('name')])
            ->withCount(['activeJobInformations', 'teams'])
            ->when($search, function (Builder $q) use ($search) {
                $q->where(function (Builder $sub) use ($search) {
                    $sub->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%')
                        ->orWhere('city', 'like', '%'.$search.'%')
                        ->orWhere('address', 'like', '%'.$search.'%');
                });
            })
            ->when($brand, fn (Builder $q) => $q->where('brand', $brand))
            ->when($city, fn (Builder $q) => $q->where('city', 'like', '%'.$city.'%'))
            ->when($isActive !== null, fn (Builder $q) => $q->where('is_active', $isActive))
            ->orderByRaw("CASE WHEN type = 'head_office' THEN 0 ELSE 1 END")
            ->orderBy('name');

        if ($limit) {
            $query->take($limit);
        }

        return $execute ? $query->get() : $query;
    }

    public function getAllPaginated(
        ?string $search,
        ?string $brand,
        ?string $city,
        ?bool $isActive,
        int $rowPerPage
    ): LengthAwarePaginator {
        return $this->getAll($search, $brand, $city, $isActive, null, false)
            ->paginate($rowPerPage);
    }

    public function getById(string|int $id): Store
    {
        $store = Store::with(['head', 'teams' => fn ($q) => $q->with('leader')->withCount('members')->orderBy('name')])
            ->withCount(['activeJobInformations', 'teams'])
            ->findOrFail($id);

        $authUser = auth()->user();
        if ($authUser && $authUser->isStoreHead()) {
            if (! in_array((int) $store->id, $authUser->managedStoreIds(), true)) {
                throw new HttpException(403, 'You do not have permission to access other stores.');
            }
        }

        // Compute active employee breakdown (internal vs vendor & by job title)
        $activeJobs = \App\Models\JobInformation::query()
            ->where('store_id', $store->id)
            ->whereNotIn('status', \App\Enums\JobStatus::inactiveValues())
            ->whereHas('employee', function ($eq) {
                $eq->whereNull('employee_profiles.deleted_at')
                    ->whereHas('user', fn ($uq) => $uq->where('is_active', true));
            })
            ->get(['job_title', 'employment_type']);

        $internalCount = 0;
        $vendorCount = 0;
        $titleCounts = [];

        foreach ($activeJobs as $job) {
            $empType = $job->employment_type instanceof \BackedEnum
                ? strtolower($job->employment_type->value)
                : strtolower((string) $job->employment_type);
            if ($empType === 'vendor') {
                $vendorCount++;
            } else {
                $internalCount++;
            }

            $title = trim((string) ($job->job_title ?: 'Unassigned'));
            $titleCounts[$title] = ($titleCounts[$title] ?? 0) + 1;
        }

        arsort($titleCounts);
        $breakdown = [];
        foreach ($titleCounts as $title => $count) {
            $breakdown[] = ['job_title' => $title, 'count' => $count];
        }

        $store->setAttribute('internal_employees_count', $internalCount);
        $store->setAttribute('vendor_employees_count', $vendorCount);
        $store->setAttribute('job_title_breakdown', $breakdown);

        return $store;
    }

    public function create(array $data): Store
    {
        return DB::transaction(function () use ($data) {
            $type = $data['type'] ?? Store::TYPE_STORE;

            $store = Store::create([
                'code' => $data['code'] ?? null,
                'type' => $type,
                'name' => $data['name'],
                'brand' => $data['brand'],
                'city' => $data['city'],
                'address' => $data['address'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'head_user_id' => $data['head_user_id'] ?? null,
            ]);

            // Auto-create default store teams for new Store locations (NOT for Head Office)
            if ($type === Store::TYPE_STORE) {
                foreach (Store::DEFAULT_STORE_TEAMS as $teamDef) {
                    Team::create([
                        'store_id' => $store->id,
                        'name' => $teamDef['name'],
                        'description' => $teamDef['description'],
                        'status' => 'active',
                        'expected_size' => 10,
                        'department' => null,
                        'responsibilities' => [],
                    ]);
                }
            }

            return $store->load(['head', 'teams' => fn ($q) => $q->withCount('members')->orderBy('name')])
                ->loadCount(['activeJobInformations', 'teams']);
        });
    }

    public function update(string|int $id, array $data): Store
    {
        return DB::transaction(function () use ($id, $data) {
            $store = Store::findOrFail($id);
            $store->update($data);

            return $store->fresh(['head', 'teams' => fn ($q) => $q->withCount('members')->orderBy('name')])
                ->loadCount(['activeJobInformations', 'teams']);
        });
    }

    public function delete(string|int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $store = Store::findOrFail($id);

            $hasActiveEmployees = $store->activeJobInformations()->exists();
            if ($hasActiveEmployees) {
                throw new HttpException(422, 'Cannot delete store that still has active employees assigned.');
            }

            return (bool) $store->delete();
        });
    }
}

<?php

namespace App\Repositories;

use App\Interfaces\StoreRepositoryInterface;
use App\Models\Store;
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
        $query = Store::query()
            ->withCount('activeJobInformations')
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
        return Store::withCount('activeJobInformations')->findOrFail($id);
    }

    public function create(array $data): Store
    {
        return DB::transaction(function () use ($data) {
            $store = Store::create([
                'code' => $data['code'] ?? null,
                'name' => $data['name'],
                'brand' => $data['brand'],
                'city' => $data['city'],
                'address' => $data['address'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $store->loadCount('activeJobInformations');
        });
    }

    public function update(string|int $id, array $data): Store
    {
        return DB::transaction(function () use ($id, $data) {
            $store = $this->getById($id);
            $store->update($data);

            return $store->fresh()->loadCount('activeJobInformations');
        });
    }

    public function delete(string|int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $store = $this->getById($id);

            $hasActiveEmployees = $store->activeJobInformations()->exists();
            if ($hasActiveEmployees) {
                throw new HttpException(422, 'Cannot delete store that still has active employees assigned.');
            }

            return (bool) $store->delete();
        });
    }
}

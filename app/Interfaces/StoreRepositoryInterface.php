<?php

namespace App\Interfaces;

use App\Models\Store;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

interface StoreRepositoryInterface
{
    public function getAll(
        ?string $search,
        ?string $brand,
        ?string $city,
        ?bool $isActive,
        ?int $limit,
        bool $execute
    ): Builder|Collection;

    public function getAllPaginated(
        ?string $search,
        ?string $brand,
        ?string $city,
        ?bool $isActive,
        int $rowPerPage
    ): LengthAwarePaginator;

    public function getById(string|int $id): Store;

    public function create(array $data): Store;

    public function update(string|int $id, array $data): Store;

    public function delete(string|int $id): bool;
}

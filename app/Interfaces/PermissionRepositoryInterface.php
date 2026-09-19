<?php

namespace App\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission;

interface PermissionRepositoryInterface
{
    public function getAll(
        ?string $search,
        ?string $group,
        ?int $limit,
        bool $execute
    ): Builder|Collection;

    public function getAllGrouped(): array;

    public function getAllPaginated(
        ?string $search,
        ?string $group,
        int $rowPerPage
    ): LengthAwarePaginator;

    public function getById(string|int $id): Permission;

    public function create(array $data): Permission;

    public function update(string|int $id, array $data): Permission;

    public function delete(string|int $id): bool;
}

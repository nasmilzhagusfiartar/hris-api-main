<?php

namespace App\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;

interface RoleRepositoryInterface
{
    public function getAll(
        ?string $search,
        ?int $limit,
        bool $execute
    ): Builder|Collection;

    public function getAllPaginated(
        ?string $search,
        int $rowPerPage
    ): LengthAwarePaginator;

    public function getById(string|int $id): Role;

    public function create(array $data): Role;

    public function update(string|int $id, array $data): Role;

    public function delete(string|int $id): bool;

    public function syncPermissions(string|int $id, array $permissions): Role;
}

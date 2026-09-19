<?php

namespace App\Repositories;

use App\Interfaces\PermissionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class PermissionRepository implements PermissionRepositoryInterface
{
    public function getAll(
        ?string $search,
        ?string $group,
        ?int $limit,
        bool $execute
    ): Builder|Collection {
        $query = Permission::where('guard_name', 'sanctum')
            ->when($search, function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%');
            })
            ->when($group, function ($q) use ($group) {
                $q->where('name', 'like', $group.'-%');
            })
            ->orderBy('name', 'asc');

        if ($limit) {
            $query->take($limit);
        }

        if ($execute) {
            return $query->get();
        }

        return $query;
    }

    public function getAllGrouped(): array
    {
        $permissions = Permission::where('guard_name', 'sanctum')
            ->orderBy('name', 'asc')
            ->get();

        $grouped = [];

        foreach ($permissions as $permission) {
            $parts = explode('-', $permission->name, 2);
            $group = $parts[0] ?? 'other';
            $action = $parts[1] ?? $permission->name;

            if (! isset($grouped[$group])) {
                $grouped[$group] = [];
            }

            $grouped[$group][] = [
                'id' => $permission->id,
                'name' => $permission->name,
                'action' => $action,
                'guard_name' => $permission->guard_name,
            ];
        }

        return $grouped;
    }

    public function getAllPaginated(
        ?string $search,
        ?string $group,
        int $rowPerPage
    ): LengthAwarePaginator {
        $query = $this->getAll($search, $group, null, false);

        return $query->paginate($rowPerPage);
    }

    public function getById(string|int $id): Permission
    {
        return Permission::where('guard_name', 'sanctum')->findOrFail($id);
    }

    public function create(array $data): Permission
    {
        return DB::transaction(function () use ($data) {
            return Permission::create([
                'name' => $data['name'],
                'guard_name' => 'sanctum',
            ]);
        });
    }

    public function update(string|int $id, array $data): Permission
    {
        return DB::transaction(function () use ($id, $data) {
            $permission = $this->getById($id);

            $permission->update([
                'name' => $data['name'],
            ]);

            return $permission;
        });
    }

    public function delete(string|int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $permission = $this->getById($id);

            return (bool) $permission->delete();
        });
    }
}

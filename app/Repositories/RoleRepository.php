<?php

namespace App\Repositories;

use App\Interfaces\RoleRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RoleRepository implements RoleRepositoryInterface
{
    private const SYSTEM_ROLES = ['manager', 'hr', 'employee', 'finance'];

    public function getAll(
        ?string $search,
        ?int $limit,
        bool $execute
    ): Builder|Collection {
        $query = Role::with(['permissions'])
            ->withCount('permissions')
            ->where('guard_name', 'sanctum')
            ->when($search, function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%');
            })
            ->orderBy('id', 'asc');

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
    ): LengthAwarePaginator {
        $query = $this->getAll($search, null, false);

        return $query->paginate($rowPerPage);
    }

    public function getById(string|int $id): Role
    {
        return Role::with(['permissions'])
            ->withCount('permissions')
            ->where('guard_name', 'sanctum')
            ->findOrFail($id);
    }

    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'sanctum',
            ]);

            if (! empty($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }

            return $role->load(['permissions'])->loadCount('permissions');
        });
    }

    public function update(string|int $id, array $data): Role
    {
        return DB::transaction(function () use ($id, $data) {
            $role = $this->getById($id);

            $role->update([
                'name' => $data['name'],
            ]);

            return $role->load(['permissions'])->loadCount('permissions');
        });
    }

    public function delete(string|int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $role = $this->getById($id);

            if (in_array($role->name, self::SYSTEM_ROLES)) {
                throw new \Exception("Role sistem '{$role->name}' tidak boleh dihapus.");
            }

            return (bool) $role->delete();
        });
    }

    public function syncPermissions(string|int $id, array $permissions): Role
    {
        return DB::transaction(function () use ($id, $permissions) {
            $role = $this->getById($id);

            $role->syncPermissions($permissions);

            return $role->load(['permissions'])->loadCount('permissions');
        });
    }
}

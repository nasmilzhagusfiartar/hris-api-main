<?php

namespace App\Repositories;

use App\DTOs\UserDto;
use App\Interfaces\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserRepository implements UserRepositoryInterface
{
    public function getById(string $id): User
    {
        return User::findOrFail($id)->load(['roles']);
    }

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $roles = [];
            if (isset($data['roles'])) {
                $roles = $this->resolveRoleNames($data['roles']);

                if (in_array('super-admin', $roles, true)) {
                    $actor = auth()->user();
                    if (! $actor || ! $actor->hasRole('super-admin')) {
                        throw new HttpException(403, 'Only Super Admin can assign the super-admin role.');
                    }
                }
            }

            $userDto = UserDto::fromArray($data);
            $user = User::create($userDto->toArray());

            if (isset($data['profile_photo'])) {
                $profilePhotoPath = \App\Helpers\SafeUploadHelper::storeImage($data['profile_photo'], 'users', 'public');
                $user->update(['profile_photo' => $profilePhotoPath]);
            }

            if (isset($data['roles'])) {
                $user->syncRoles($roles);
            }

            return $user;
        });
    }

    public function update(string $id, array $data): User
    {
        return DB::transaction(function () use ($id, $data) {
            $user = $this->getById($id);

            if (isset($data['roles'])) {
                $roles = $this->resolveRoleNames($data['roles']);
                $targetIsSuperAdmin = $user->hasRole('super-admin');
                $assigningSuperAdmin = in_array('super-admin', $roles, true);

                if ($targetIsSuperAdmin && ! $assigningSuperAdmin) {
                    throw new HttpException(403, 'Super Admin user role cannot be downgraded or removed.');
                }

                if ($assigningSuperAdmin && ! $targetIsSuperAdmin) {
                    $actor = auth()->user();
                    if (! $actor || ! $actor->hasRole('super-admin')) {
                        throw new HttpException(403, 'Only Super Admin can assign the super-admin role.');
                    }
                }
            }

            $userDto = UserDto::fromArrayForUpdate($data, $user);
            $user->update($userDto->toArray());

            if (isset($data['profile_photo'])) {
                if ($user->profile_photo && Storage::disk('public')->exists($user->profile_photo)) {
                    Storage::disk('public')->delete($user->profile_photo);
                }

                $profilePhotoPath = \App\Helpers\SafeUploadHelper::storeImage($data['profile_photo'], 'users', 'public');
                $user->update(['profile_photo' => $profilePhotoPath]);
            }

            if (isset($data['roles'])) {
                $user->syncRoles($roles);
            }

            return $user;
        });
    }

    private function resolveRoleNames(array $rawRoles): array
    {
        return collect($rawRoles)
            ->map(function ($role) {
                if (is_numeric($role)) {
                    return optional(Role::find($role))->name;
                }

                return $role;
            })
            ->filter()
            ->values()
            ->all();
    }
}

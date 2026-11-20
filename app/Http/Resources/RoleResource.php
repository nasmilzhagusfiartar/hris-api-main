<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    private const SYSTEM_ROLES = ['super-admin', 'admin', 'security', 'manager', 'hr', 'employee', 'finance'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'guard_name' => $this->guard_name,
            'is_system_role' => in_array($this->name, self::SYSTEM_ROLES),
            'is_super_admin' => $this->name === 'super-admin',
            'permissions_count' => $this->permissions_count ?? $this->permissions->count(),
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}

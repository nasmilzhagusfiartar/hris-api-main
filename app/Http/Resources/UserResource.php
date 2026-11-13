<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $photoPath = $this->profile_photo;
        if ($photoPath && str_contains($photoPath, '/storage/')) {
            $photoPath = ltrim(substr($photoPath, strpos($photoPath, '/storage/') + 9), '/');
        }

        return [
            'id' => $this->id,
            'profile_photo' => $photoPath ? Storage::disk('public')->url($photoPath) : null,
            'name' => $this->name,
            'email' => $this->email,
            'employee_profile' => new EmployeeProfileResource($this->whenLoaded('employeeProfile')),
            'roles' => $this->whenLoaded('roles', function () {
                return $this->roles->pluck('name');
            }),
            'permissions' => $this->whenLoaded('permissions', function () {
                return $this->getAllPermissions()->pluck('name');
            }),
            'token' => $this->when(isset($this->token), $this->token),
            'created_at' => $this->created_at,
        ];
    }
}

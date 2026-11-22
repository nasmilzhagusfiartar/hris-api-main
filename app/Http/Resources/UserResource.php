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
            'permissions' => $this->when(
                $this->relationLoaded('permissions') || $this->relationLoaded('roles'),
                function () {
                    return $this->getAllPermissions()->pluck('name');
                }
            ),
            'token' => $this->when(isset($this->token), $this->token),
            'mfa_enabled' => $this->hasMfaEnabled(),
            'mfa_setup_required' => config('mfa.enabled', true) && $this->requiresMfa() && ! $this->hasMfaEnabled(),
            'is_vendor' => $this->isVendorEmployee(),
            'vendor_name' => $this->employeeProfile?->jobInformation?->vendor_name,
            'store_name' => $this->employeeProfile?->jobInformation?->store?->name,
            'is_store_head' => $this->isStoreHead(),
            'headed_store_id' => $this->isStoreHead() ? ($this->managedStoreIds()[0] ?? null) : null,
            'created_at' => $this->created_at,
        ];
    }
}

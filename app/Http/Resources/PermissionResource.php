<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $group = str_contains($this->name, '-') ? explode('-', $this->name)[0] : 'other';

        return [
            'id' => $this->id,
            'name' => $this->name,
            'group' => $group,
            'guard_name' => $this->guard_name,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}

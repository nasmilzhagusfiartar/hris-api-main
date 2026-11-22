<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type ?? 'store',
            'name' => $this->name,
            'brand' => $this->brand,
            'city' => $this->city,
            'address' => $this->address,
            'is_active' => (bool) $this->is_active,
            'head_user_id' => $this->head_user_id,
            'head' => $this->whenLoaded('head', function () {
                return $this->head ? [
                    'id' => $this->head->id,
                    'name' => $this->head->name,
                    'email' => $this->head->email,
                ] : null;
            }),
            'teams_count' => (int) ($this->teams_count ?? ($this->relationLoaded('teams') ? $this->teams->count() : 0)),
            'teams' => TeamResource::collection($this->whenLoaded('teams')),
            'active_employees_count' => (int) ($this->active_job_informations_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

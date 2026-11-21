<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class TeamResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $iconPath = $this->icon;
        if ($iconPath && str_contains($iconPath, '/storage/')) {
            $iconPath = ltrim(substr($iconPath, strpos($iconPath, '/storage/') + 9), '/');
        }

        $department = $this->department instanceof \BackedEnum ? $this->department->value : $this->department;
        $status = $this->status instanceof \BackedEnum ? $this->status->value : $this->status;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'expected_size' => $this->expected_size,
            'description' => $this->description,
            'icon' => $iconPath ? Storage::disk('public')->url($iconPath) : null,
            'department' => $department,
            'status' => $status,
            'team_lead_id' => $this->team_lead_id,
            'leader' => new UserResource($this->whenLoaded('leader')),
            'responsibilities' => $this->responsibilities ?? [],
            'members_count' => $this->members_count ?? ($this->relationLoaded('members') ? $this->members->count() : 0),
            'members' => TeamMemberResource::collection($this->whenLoaded('members')),
            'projects_count' => $this->projects_count ?? null,
            'recent_activities' => $this->recent_activities ?? [],
            'created_at' => $this->created_at,
        ];
    }
}

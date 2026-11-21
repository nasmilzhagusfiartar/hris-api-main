<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assignee = $this->relationLoaded('assignee') ? $this->assignee : null;
        $assigneeDeactivated = $assignee
            ? ($assignee->trashed()
                || ($assignee->relationLoaded('user') && $assignee->user && $assignee->user->is_active === false)
                || ($assignee->relationLoaded('jobInformation') && \App\Enums\JobStatus::isInactiveStatus($assignee->jobInformation?->status)))
            : false;

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'description' => $this->description,
            'assignee_id' => $this->assignee_id,
            'assignee_deactivated' => $assigneeDeactivated,
            'assignee_label' => $assigneeDeactivated ? 'Deactivated employee' : ($assignee?->user?->name ?? null),
            'priority' => $this->priority,
            'status' => $this->status,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'project' => new ProjectResource($this->whenLoaded('project')),
            'assignee' => new EmployeeProfileResource($this->whenLoaded('assignee')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobInformationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_title' => $this->job_title,
            'team' => new TeamResource($this->whenLoaded('team')),
            'department' => $this->department,
            'store_id' => $this->store_id,
            'store' => $this->whenLoaded('store', function () {
                return $this->store ? [
                    'id' => $this->store->id,
                    'code' => $this->store->code,
                    'name' => $this->store->name,
                    'brand' => $this->store->brand,
                    'city' => $this->store->city,
                ] : null;
            }),
            'years_experience' => $this->years_experience,
            'status' => $this->status,
            'employment_type' => $this->employment_type,
            'vendor_name' => $this->vendor_name,
            'work_location' => $this->work_location,
            'start_date' => $this->start_date,
            'monthly_salary' => $this->monthly_salary,
            'skill_level' => $this->skill_level,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeEducationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'level' => $this->level,
            'institution' => $this->institution,
            'major' => $this->major,
            'start_year' => $this->start_year !== null ? (int) $this->start_year : null,
            'end_year' => $this->end_year !== null ? (int) $this->end_year : null,
            'gpa' => $this->gpa !== null ? (float) $this->gpa : null,
            'is_highest' => (bool) $this->is_highest,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

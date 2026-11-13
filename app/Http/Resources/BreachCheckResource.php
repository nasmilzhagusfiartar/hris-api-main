<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BreachCheckResource extends JsonResource
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
            'email' => $this->email,
            'is_breached' => (bool) $this->is_breached,
            'breaches' => is_array($this->breaches) ? array_values($this->breaches) : [],
            'checked_at' => $this->checked_at instanceof \DateTimeInterface
                ? $this->checked_at->toIso8601String()
                : $this->checked_at,
        ];
    }
}

<?php

namespace App\DTOs;

use App\Models\Team;

class TeamDto
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $expected_size,
        public readonly ?string $description,
        public readonly ?string $icon,
        public readonly ?string $department,
        public readonly string $status = 'active',
        public readonly ?int $team_lead_id = null,
        public readonly array $responsibilities = [],
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'expected_size' => $this->expected_size,
            'description' => $this->description,
            'icon' => $this->icon,
            'department' => $this->department,
            'status' => $this->status,
            'team_lead_id' => $this->team_lead_id,
            'responsibilities' => $this->responsibilities,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            expected_size: isset($data['expected_size']) && $data['expected_size'] !== '' ? (int) $data['expected_size'] : null,
            description: $data['description'] ?? null,
            icon: is_string($data['icon'] ?? null) ? $data['icon'] : null,
            department: $data['department'] ?? null,
            status: $data['status'] ?? 'active',
            team_lead_id: isset($data['team_lead_id']) && $data['team_lead_id'] !== '' ? (int) $data['team_lead_id'] : null,
            responsibilities: is_array($data['responsibilities'] ?? null) ? array_values($data['responsibilities']) : [],
        );
    }

    public static function fromArrayForUpdate(array $data, Team $existingTeam): self
    {
        $existingDept = $existingTeam->department instanceof \BackedEnum
            ? $existingTeam->department->value
            : $existingTeam->department;

        $existingStatus = $existingTeam->status instanceof \BackedEnum
            ? $existingTeam->status->value
            : ($existingTeam->status ?? 'active');

        $teamLeadId = array_key_exists('team_lead_id', $data)
            ? ($data['team_lead_id'] !== null && $data['team_lead_id'] !== '' ? (int) $data['team_lead_id'] : null)
            : ($existingTeam->team_lead_id !== null ? (int) $existingTeam->team_lead_id : null);

        $responsibilities = array_key_exists('responsibilities', $data)
            ? (is_array($data['responsibilities']) ? array_values($data['responsibilities']) : [])
            : (is_array($existingTeam->responsibilities) ? $existingTeam->responsibilities : []);

        return new self(
            name: $data['name'] ?? $existingTeam->name,
            expected_size: array_key_exists('expected_size', $data)
                ? ($data['expected_size'] !== null && $data['expected_size'] !== '' ? (int) $data['expected_size'] : null)
                : $existingTeam->expected_size,
            description: array_key_exists('description', $data) ? $data['description'] : $existingTeam->description,
            icon: $existingTeam->icon,
            department: array_key_exists('department', $data) ? ($data['department'] ?: $existingDept) : $existingDept,
            status: $data['status'] ?? $existingStatus,
            team_lead_id: $teamLeadId,
            responsibilities: $responsibilities,
        );
    }
}

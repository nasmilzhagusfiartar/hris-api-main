<?php

namespace App\DTOs;

use App\Models\JobInformation;

class JobInformationDto
{
    public function __construct(
        public readonly string $employee_id,
        public readonly string $job_title,
        public readonly ?string $team_id,
        public readonly ?string $department,
        public readonly ?int $store_id,
        public readonly int $years_experience,
        public readonly string $status,
        public readonly string $employment_type,
        public readonly ?string $vendor_name,
        public readonly string $work_location,
        public readonly string $start_date,
        public readonly ?string $end_date,
        public readonly float $monthly_salary,
        public readonly string $skill_level,
    ) {}

    public function toArray(): array
    {
        return [
            'employee_id' => $this->employee_id,
            'job_title' => $this->job_title,
            'team_id' => $this->team_id,
            'department' => $this->department,
            'store_id' => $this->store_id,
            'years_experience' => $this->years_experience,
            'status' => $this->status,
            'employment_type' => $this->employment_type,
            'vendor_name' => $this->employment_type === 'vendor' ? $this->vendor_name : null,
            'work_location' => $this->work_location,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'monthly_salary' => $this->monthly_salary,
            'skill_level' => $this->skill_level,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            employee_id: (string) $data['employee_id'],
            job_title: $data['job_title'],
            team_id: isset($data['team_id']) && $data['team_id'] !== '' ? (string) $data['team_id'] : null,
            department: isset($data['department']) && $data['department'] !== '' ? (string) $data['department'] : null,
            store_id: isset($data['store_id']) && $data['store_id'] !== '' ? (int) $data['store_id'] : null,
            years_experience: (int) $data['years_experience'],
            status: $data['status'],
            employment_type: $data['employment_type'],
            vendor_name: isset($data['vendor_name']) && trim((string) $data['vendor_name']) !== '' ? trim((string) $data['vendor_name']) : null,
            work_location: $data['work_location'],
            start_date: $data['start_date'],
            end_date: isset($data['end_date']) && $data['end_date'] !== '' ? (string) $data['end_date'] : null,
            monthly_salary: (float) $data['monthly_salary'],
            skill_level: $data['skill_level'],
        );
    }

    public static function fromArrayForUpdate(array $data, JobInformation $existingJob): self
    {
        $employmentType = $data['employment_type'] ?? $existingJob->employment_type ?? 'full_time';
        $status = $data['status'] ?? $existingJob->status ?? 'active';
        $endDate = array_key_exists('end_date', $data)
            ? ($data['end_date'] !== '' && $data['end_date'] !== null ? (string) $data['end_date'] : null)
            : ($existingJob->end_date ? $existingJob->end_date->format('Y-m-d') : null);
        if (! \App\Enums\JobStatus::isInactiveStatus($status)) {
            $endDate = null;
        }

        return new self(
            employee_id: (string) ($data['employee_id'] ?? $existingJob->employee_id),
            job_title: $data['job_title'] ?? $existingJob->job_title,
            team_id: array_key_exists('team_id', $data)
                ? ($data['team_id'] !== '' && $data['team_id'] !== null ? (string) $data['team_id'] : null)
                : ($existingJob->team_id ? (string) $existingJob->team_id : null),
            department: array_key_exists('department', $data)
                ? ($data['department'] !== '' ? $data['department'] : null)
                : $existingJob->department,
            store_id: array_key_exists('store_id', $data)
                ? ($data['store_id'] !== '' && $data['store_id'] !== null ? (int) $data['store_id'] : null)
                : $existingJob->store_id,
            years_experience: (int) ($data['years_experience'] ?? $existingJob->years_experience ?? 0),
            status: $status,
            employment_type: $employmentType,
            vendor_name: array_key_exists('vendor_name', $data)
                ? (trim((string) $data['vendor_name']) !== '' ? trim((string) $data['vendor_name']) : null)
                : $existingJob->vendor_name,
            work_location: $data['work_location'] ?? $existingJob->work_location ?? 'office',
            start_date: $data['start_date'] ?? ($existingJob->start_date ? $existingJob->start_date->format('Y-m-d') : now()->format('Y-m-d')),
            end_date: $endDate,
            monthly_salary: isset($data['monthly_salary']) ? (float) $data['monthly_salary'] : ($existingJob->monthly_salary ?? 0.0),
            skill_level: $data['skill_level'] ?? $existingJob->skill_level ?? 'beginner',
        );
    }
}

<?php

namespace App\Enums;

enum EmploymentType: string
{
    case FULL_TIME = 'full_time';
    case PART_TIME = 'part_time';
    case CONTRACT = 'contract';
    case INTERN = 'intern';
    case VENDOR = 'vendor';

    public function label(): string
    {
        return match ($this) {
            self::FULL_TIME => 'Full-time',
            self::PART_TIME => 'Part-time',
            self::CONTRACT => 'Contract',
            self::INTERN => 'Intern',
            self::VENDOR => 'Vendor',
        };
    }

    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
        ];
    }
}

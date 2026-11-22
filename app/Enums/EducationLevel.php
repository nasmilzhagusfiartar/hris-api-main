<?php

namespace App\Enums;

enum EducationLevel: string
{
    case SD = 'sd';
    case SMP = 'smp';
    case SMA_SMK = 'sma_smk';
    case D3 = 'd3';
    case D4 = 'd4';
    case S1 = 's1';
    case S2 = 's2';
    case S3 = 's3';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SD => 'SD (Elementary)',
            self::SMP => 'SMP (Junior High)',
            self::SMA_SMK => 'SMA / SMK (High School)',
            self::D3 => 'D3 (Diploma 3)',
            self::D4 => 'D4 (Diploma 4)',
            self::S1 => 'S1 (Bachelor)',
            self::S2 => 'S2 (Master)',
            self::S3 => 'S3 (Doctorate)',
            self::OTHER => 'Other',
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

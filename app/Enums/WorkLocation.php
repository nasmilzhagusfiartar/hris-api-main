<?php

namespace App\Enums;

enum WorkLocation: string
{
    case OFFICE = 'office';
    case STORE = 'store';
    case REMOTE = 'remote';
    case EVENT = 'event';

    public function label(): string
    {
        return match ($this) {
            self::OFFICE => 'Office',
            self::STORE => 'Store',
            self::REMOTE => 'Remote',
            self::EVENT => 'Event',
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

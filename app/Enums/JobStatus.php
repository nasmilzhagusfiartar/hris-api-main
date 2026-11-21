<?php

namespace App\Enums;

enum JobStatus: string
{
    case ACTIVE = 'active';
    case ON_LEAVE = 'on_leave';
    case RESIGNED = 'resigned';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::ON_LEAVE => 'On Leave',
            self::RESIGNED => 'Resigned',
        };
    }

    /**
     * Statuses that deactivate the employee's user account.
     *
     * @return list<string>
     */
    public static function inactiveValues(): array
    {
        return [self::RESIGNED->value, 'inactive', 'terminated'];
    }

    public static function isInactiveStatus(?string $status): bool
    {
        if ($status === null) {
            return false;
        }

        return in_array(strtolower($status), self::inactiveValues(), true);
    }

    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
        ];
    }
}

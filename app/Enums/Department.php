<?php

namespace App\Enums;

enum Department: string
{
    case SALES = 'sales';
    case STORE_OPERATIONS = 'store_operations';
    case WAREHOUSE_LOGISTICS = 'warehouse_logistics';
    case MARKETING = 'marketing';
    case FINANCE_ACCOUNTING = 'finance_accounting';
    case HUMAN_RESOURCES = 'human_resources';
    case IT = 'it';
    case PURCHASING = 'purchasing';
    case GENERAL_AFFAIRS = 'general_affairs';

    public function label(): string
    {
        return match ($this) {
            self::SALES => 'Sales',
            self::STORE_OPERATIONS => 'Store Operations',
            self::WAREHOUSE_LOGISTICS => 'Warehouse & Logistics',
            self::MARKETING => 'Marketing',
            self::FINANCE_ACCOUNTING => 'Finance & Accounting',
            self::HUMAN_RESOURCES => 'Human Resources',
            self::IT => 'IT',
            self::PURCHASING => 'Purchasing',
            self::GENERAL_AFFAIRS => 'General Affairs',
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

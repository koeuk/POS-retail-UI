<?php

namespace App\Enums;

enum InventoryLogType: string
{
    case Sale = 'sale';
    case Restock = 'restock';
    case Adjustment = 'adjustment';
    case Return = 'return';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Sales are the only type that reduces stock automatically. */
    public function isNegative(): bool
    {
        return $this === self::Sale;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Completed = 'completed';
    case Refunded = 'refunded';
    case Void = 'void';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** Only completed orders count toward sales totals and stock movement. */
    public function countsTowardSales(): bool
    {
        return $this === self::Completed;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

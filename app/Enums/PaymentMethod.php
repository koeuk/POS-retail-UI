<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Qr = 'qr';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Card => 'Card',
            self::Qr => 'QR',
            self::Credit => 'Credit',
        };
    }

    /** Change is only ever given against cash. */
    public function givesChange(): bool
    {
        return $this === self::Cash;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

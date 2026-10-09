<?php

namespace App\Enums;

/**
 * Why the goods left the shelf.
 *
 * Three answers, and they mean different things for the money:
 *
 *  - Customer  an ordinary sale. Revenue, paid in full at the till.
 *  - Debt      a sale on credit. Revenue *and* a receivable: the total counts,
 *              nothing is paid yet, and it MUST be tied to a named customer or
 *              there is nobody to collect from.
 *  - Myself    the owner consumed it. Stock leaves, but this is NOT revenue —
 *              counting it would inflate the takings every time someone eats a
 *              chocolate bar. It is tracked so the stock movement has a reason.
 */
enum SaleType: string
{
    case Customer = 'customer';
    case Debt = 'debt';
    case Myself = 'myself';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Debt => 'In debt',
            self::Myself => 'Myself',
        };
    }

    /** Does this sale count toward the shop's takings? */
    public function isRevenue(): bool
    {
        return $this !== self::Myself;
    }

    /** Is money still owed after the sale is recorded? */
    public function isReceivable(): bool
    {
        return $this === self::Debt;
    }

    /** Must a customer be attached for this sale to make sense? */
    public function requiresCustomer(): bool
    {
        return $this === self::Debt;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

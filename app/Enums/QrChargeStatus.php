<?php

namespace App\Enums;

enum QrChargeStatus: string
{
    /** Shown to the customer, not yet paid. */
    case Pending = 'pending';

    /** The provider confirmed the money arrived. */
    case Paid = 'paid';

    /** A cashier confirmed it by hand; the provider never did. */
    case Manual = 'manual';

    /** The QR timed out unpaid. */
    case Expired = 'expired';

    /** The provider reported the payment failed. */
    case Failed = 'failed';

    /** The cashier backed out — changed method or closed the sale. */
    case Cancelled = 'cancelled';

    /** No further change is expected — except a late payment on an expired QR, which reconcile still catches. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Paid, self::Manual, self::Failed], true);
    }

    /** Money is considered received. */
    public function isSettled(): bool
    {
        return $this === self::Paid || $this === self::Manual;
    }
}

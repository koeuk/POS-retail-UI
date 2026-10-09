<?php

namespace App\Payments;

use Carbon\CarbonInterface;

/** What the till wants a QR for — provider-neutral. */
final class ChargeRequest
{
    public function __construct(
        /** Decimal string in the shop's currency, e.g. "16400" or "4.10". */
        public readonly string $amount,
        public readonly string $currency,
        public readonly CarbonInterface $expiresAt,
        public readonly ?string $billNumber = null,
        public readonly ?string $storeLabel = null,
        public readonly ?string $terminalLabel = null,
    ) {}
}

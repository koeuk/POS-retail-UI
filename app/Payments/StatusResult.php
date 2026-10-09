<?php

namespace App\Payments;

use App\Enums\QrChargeStatus;

/** A provider's answer to "has this been paid?". */
final class StatusResult
{
    public function __construct(
        public readonly QrChargeStatus $status,
        /** The provider's own transaction id, once paid. */
        public readonly ?string $providerRef = null,
        /** Who paid — an account id, never shown on the till. */
        public readonly ?string $payer = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}

    public static function pending(?string $message = null): self
    {
        return new self(QrChargeStatus::Pending, message: $message);
    }
}

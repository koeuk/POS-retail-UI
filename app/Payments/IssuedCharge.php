<?php

namespace App\Payments;

/** A QR a provider has minted for one sale. */
final class IssuedCharge
{
    public function __construct(
        /** The text encoded in the QR image. */
        public readonly string $qr,
        /** What the provider will look this charge up by (Bakong: the QR's MD5). */
        public readonly string $reference,
        /** Anything provider-specific worth keeping for reconciliation. */
        public readonly array $meta = [],
    ) {}
}

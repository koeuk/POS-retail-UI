<?php

namespace App\Payments\Khqr;

use InvalidArgumentException;

/**
 * Builds a KHQR string — the text inside the QR code every Cambodian banking
 * app scans. KHQR is EMVCo merchant-presented QR with Bakong's own tags, so
 * the format is a flat run of TLV fields ("tag, two-digit length, value")
 * closed by a CRC-16 over everything before it.
 *
 * Deliberately dependency-free: the format is small, stable and published,
 * and owning it means the offline static QR and the online dynamic one are
 * built by exactly the same code.
 *
 * Only ASCII is accepted in the fields — EMV lengths count characters, and
 * banking apps disagree on how to count Khmer script. Validation on the
 * settings screen keeps non-ASCII out before it ever reaches here.
 */
final class KhqrPayload
{
    /** ISO 4217 numeric codes — the only two currencies Bakong settles. */
    public const CURRENCIES = ['KHR' => '116', 'USD' => '840'];

    public const NAME_MAX = 25;

    public const CITY_MAX = 15;

    /**
     * @param  string  $accountId  Bakong account, e.g. "shopname@aclb".
     * @param  float|int|string|null  $amount  Null for a static QR the customer keys the amount into.
     * @param  string|null  $merchantId  Set for a merchant account (tag 30); null for an individual one (tag 29).
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $merchantName,
        public readonly string $merchantCity,
        public readonly string $currency,
        public readonly float|int|string|null $amount = null,
        public readonly ?string $billNumber = null,
        public readonly ?string $storeLabel = null,
        public readonly ?string $terminalLabel = null,
        public readonly ?string $merchantId = null,
        public readonly ?string $acquiringBank = null,
        public readonly ?int $expiresAtMs = null,
        public readonly ?int $createdAtMs = null,
    ) {
        if (! isset(self::CURRENCIES[$currency])) {
            throw new InvalidArgumentException("KHQR only supports KHR and USD, not {$currency}.");
        }

        if (! preg_match('/^[^@\s]+@[^@\s]+$/', $accountId)) {
            throw new InvalidArgumentException('A Bakong account ID looks like name@bank.');
        }
    }

    public function isDynamic(): bool
    {
        return $this->amount !== null;
    }

    public function toString(): string
    {
        $body = $this->tlv('00', '01')
            // 11 = static (reusable, customer keys the amount), 12 = dynamic (one sale).
            .$this->tlv('01', $this->isDynamic() ? '12' : '11')
            .$this->accountInformation()
            .$this->tlv('52', '5999')
            .$this->tlv('53', self::CURRENCIES[$this->currency])
            .($this->isDynamic() ? $this->tlv('54', $this->formatAmount()) : '')
            .$this->tlv('58', 'KH')
            .$this->tlv('59', $this->clip($this->merchantName, self::NAME_MAX))
            .$this->tlv('60', $this->clip($this->merchantCity, self::CITY_MAX))
            .$this->additionalData()
            .$this->timestamps();

        // The CRC covers its own tag and length, so they are appended first.
        $body .= '6304';

        return $body.self::crc16($body);
    }

    /**
     * The fingerprint Bakong indexes transactions by. Checking a payment is
     * "has anyone paid the QR whose MD5 is this?".
     */
    public function md5(): string
    {
        return md5($this->toString());
    }

    /** CRC-16/CCITT-FALSE: polynomial 0x1021, initial 0xFFFF, no reflection. */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    private function accountInformation(): string
    {
        // Merchant accounts carry a merchant ID and the acquiring bank; an
        // individual account is the Bakong ID alone.
        if ($this->merchantId) {
            return $this->tlv('30',
                $this->tlv('00', $this->accountId)
                .$this->tlv('01', $this->merchantId)
                .$this->tlv('02', (string) $this->acquiringBank)
            );
        }

        return $this->tlv('29',
            $this->tlv('00', $this->accountId)
            .($this->acquiringBank ? $this->tlv('02', $this->acquiringBank) : '')
        );
    }

    private function additionalData(): string
    {
        $inner = ($this->billNumber ? $this->tlv('01', $this->clip($this->billNumber, 25)) : '')
            .($this->storeLabel ? $this->tlv('03', $this->clip($this->storeLabel, 25)) : '')
            .($this->terminalLabel ? $this->tlv('07', $this->clip($this->terminalLabel, 25)) : '');

        return $inner === '' ? '' : $this->tlv('62', $inner);
    }

    /** Bakong rejects a dynamic QR without an expiry; a static one has neither. */
    private function timestamps(): string
    {
        if (! $this->isDynamic()) {
            return '';
        }

        $created = $this->createdAtMs ?? (int) floor(microtime(true) * 1000);

        return $this->tlv('99',
            $this->tlv('00', (string) $created)
            .($this->expiresAtMs ? $this->tlv('01', (string) $this->expiresAtMs) : '')
        );
    }

    /** Riel is whole; dollars carry at most two places. */
    private function formatAmount(): string
    {
        $amount = (float) $this->amount;

        if ($amount <= 0) {
            throw new InvalidArgumentException('A dynamic KHQR needs an amount above zero.');
        }

        return $this->currency === 'KHR'
            ? (string) (int) round($amount)
            : rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    private function tlv(string $tag, string $value): string
    {
        $length = strlen($value);

        if ($length > 99) {
            throw new InvalidArgumentException("KHQR field {$tag} is too long ({$length} characters).");
        }

        return $tag.str_pad((string) $length, 2, '0', STR_PAD_LEFT).$value;
    }

    private function clip(string $value, int $max): string
    {
        return substr(trim($value), 0, $max);
    }
}

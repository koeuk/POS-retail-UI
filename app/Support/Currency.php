<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The shop's currency — the one prices are actually *stored* in.
 *
 * This used to be a display layer over USD: every amount was kept in dollars
 * and multiplied by a rate on the way out. That cannot represent riel. A US
 * cent is 40៛ at a 4,000 rate, so a 500៛ price had nowhere to live: it became
 * 13 cents and came back as 520៛, and the error reached shelf labels,
 * receipts and reports alike.
 *
 * So the stored number is now the shop's own money. Type 500៛ and 500៛ is
 * what is stored, charged, printed and totalled — exactly.
 *
 * Riel has no fractional unit — a price is ៛4,000, never ៛4,000.50 — so each
 * currency carries its own decimal count, and all arithmetic happens in that
 * currency's minor unit (cents for dollars, whole riel for riel).
 */
final class Currency
{
    public const USD = 'USD';

    public const KHR = 'KHR';

    /** @var array<string, array{symbol: string, decimals: int, name: string}> */
    public const DEFINITIONS = [
        self::USD => ['symbol' => '$', 'decimals' => 2, 'name' => 'US Dollar'],
        self::KHR => ['symbol' => '៛', 'decimals' => 0, 'name' => 'Cambodian Riel'],
    ];

    /** A sane fallback for a fresh install; the real figure lives in settings. */
    public const DEFAULT_RATE = 4100.0;

    private function __construct(
        public readonly string $code,
        public readonly string $symbol,
        public readonly int $decimals,
        public readonly float $rielPerUsd,
    ) {}

    /** The currency the shop is currently set to display. */
    public static function current(): self
    {
        $code = Setting::get('currency', self::USD);

        // Guard against a stale or hand-edited row: anything unknown is USD.
        if (! array_key_exists($code, self::DEFINITIONS)) {
            $code = self::USD;
        }

        return self::make($code);
    }

    public static function make(string $code): self
    {
        $def = self::DEFINITIONS[$code] ?? self::DEFINITIONS[self::USD];

        return new self(
            code: $code,
            symbol: $def['symbol'],
            decimals: $def['decimals'],
            rielPerUsd: (float) (Setting::get('riel_per_usd') ?? self::DEFAULT_RATE),
        );
    }

    /**
     * How many minor units make one whole unit: 100 for dollars, 1 for riel.
     * All money arithmetic runs in these, because integers do not drift.
     */
    public function minorFactor(): int
    {
        return 10 ** $this->decimals;
    }

    /**
     * "$4.00" or "៛16,400".
     *
     * No conversion: the stored amount is already in this currency. Only the
     * one-off migration that changed the base ever converted anything.
     */
    public function format(string|float|int|null $amount): string
    {
        return $this->symbol.number_format((float) ($amount ?? 0), $this->decimals);
    }

    /**
     * Convert between the two currencies. Used by the migration that moved the
     * stored base, and by nothing on a request path — a price is not a
     * conversion of some other price.
     */
    public function fromUsd(string|float|int|null $usd): float
    {
        $amount = (float) ($usd ?? 0);

        return $this->code === self::USD
            ? round($amount, 2)
            : round($amount * $this->rielPerUsd, $this->decimals);
    }

    /** What the frontend needs to format prices identically. */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'symbol' => $this->symbol,
            'decimals' => $this->decimals,
            'riel_per_usd' => $this->rielPerUsd,
        ];
    }
}

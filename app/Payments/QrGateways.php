<?php

namespace App\Payments;

use App\Payments\Khqr\KhqrPayload;
use App\Support\Currency;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves providers by key from config/payments.php. The one place that
 * knows which classes exist; everything else asks for `current()`.
 */
final class QrGateways
{
    public function __construct(private readonly Container $container) {}

    /** The provider the shop has chosen in Settings → Payments. */
    public function current(): QrGateway
    {
        return $this->get(PaymentSettings::provider());
    }

    public function get(string $key): QrGateway
    {
        $class = config("payments.providers.{$key}");

        if (! $class) {
            throw new InvalidArgumentException("Unknown QR payment provider [{$key}].");
        }

        return $this->container->make($class);
    }

    /** @return array<int, array{key: string, label: string, verifies: bool}> */
    public function options(): array
    {
        return collect(array_keys(config('payments.providers', [])))
            ->map(fn (string $key) => $this->get($key))
            ->map(fn (QrGateway $g) => ['key' => $g->key(), 'label' => $g->label(), 'verifies' => $g->verifies()])
            ->values()
            ->all();
    }

    /**
     * The shop's reusable KHQR — no amount, never expires. Shared by every
     * provider that settles through Bakong, which in Cambodia is all of them.
     */
    public static function staticKhqr(): ?string
    {
        $account = PaymentSettings::accountId();

        if (! $account) {
            return null;
        }

        try {
            return (new KhqrPayload(
                accountId: $account,
                merchantName: PaymentSettings::merchantName(),
                merchantCity: PaymentSettings::merchantCity(),
                currency: Currency::current()->code,
                merchantId: PaymentSettings::merchantId(),
                acquiringBank: PaymentSettings::acquiringBank(),
            ))->toString();
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}

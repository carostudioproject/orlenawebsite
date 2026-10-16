<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * Chooses the payment gateway. DOKU_ENABLED=false and DOKU_MODE not "demo" means no online payments:
 * staff confirm orders and record payments with "Mark as paid" (provider "manual", no gateway).
 */
class PaymentGateways
{
    /** Methods paid on our own page /bayar, in the order they are offered. */
    public const METHODS = ['va_bca' => DokuVaGateway::class, 'qris' => DokuQrisGateway::class];

    public const METHOD_LABELS = ['va_bca' => 'Virtual Account BCA', 'qris' => 'QRIS'];

    private const BY_PROVIDER = ['doku' => DokuCheckoutGateway::class, 'doku_qris' => DokuQrisGateway::class, 'doku_va' => DokuVaGateway::class, 'demo' => DemoGateway::class];

    private const BY_MODE = ['checkout' => DokuCheckoutGateway::class, 'va' => DokuCheckoutGateway::class, 'qris' => DokuQrisGateway::class];

    /**
     * Demo payments are marked paid without money and sent to Erzap, so a production install only allows them
     * when DOKU_ALLOW_DEMO=true says so (a demo server). Otherwise DOKU_MODE=demo is treated as "off".
     */
    public static function demo(): bool
    {
        return config('services.doku.mode') === 'demo' && (! app()->isProduction() || (bool) config('services.doku.allow_demo'));
    }

    /** The configured mode, or "off" when demo is configured but not allowed here. */
    public static function mode(): string
    {
        $mode = (string) config('services.doku.mode', 'qris');

        return $mode === 'demo' && ! self::demo() ? 'off' : $mode;
    }

    /** Whether confirming an order creates a payment link. */
    public static function enabled(): bool
    {
        return ((bool) config('services.doku.enabled') && self::mode() !== 'off') || self::demo();
    }

    /**
     * Methods the customer can choose between on /bayar. DOKU_MODE=direct (and demo) offer DOKU_METHODS;
     * qris offers QRIS only; the DOKU Checkout modes choose on DOKU's page instead.
     */
    public static function methods(): array
    {
        return match (self::mode()) {
            'direct', 'demo' => array_values(array_intersect((array) config('services.doku.methods', []), array_keys(self::METHODS))) ?: ['qris'],
            'qris' => ['qris'],
            default => [],
        };
    }

    /**
     * With more than one method on /bayar, confirming creates no link: the customer picks VA BCA or QRIS first
     * and the link is created then (CreatePaymentLink::choose).
     */
    public static function customerChooses(): bool
    {
        return self::enabled() && in_array(self::mode(), ['direct', 'demo'], true) && count(self::methods()) > 1;
    }

    /** Method for a new payment link: the requested one when it is offered, otherwise the first offered. */
    public static function method(?string $requested = null): ?string
    {
        $methods = self::methods();

        return in_array($requested, $methods, true) ? $requested : ($methods[0] ?? null);
    }

    /** Gateway for new payment links. */
    public function current(?string $method = null): PaymentGateway
    {
        if (self::demo()) {
            return app(DemoGateway::class);
        }
        if (self::mode() === 'direct') {
            return app(self::METHODS[self::method($method)] ?? DokuQrisGateway::class);
        }

        return app(self::BY_MODE[self::mode()] ?? DokuQrisGateway::class);
    }

    /** Gateway that created an existing payment; null for manual payments. */
    public function for(Payment $payment): ?PaymentGateway
    {
        $class = self::BY_PROVIDER[$payment->provider] ?? null;

        return $class ? app($class) : null;
    }
}

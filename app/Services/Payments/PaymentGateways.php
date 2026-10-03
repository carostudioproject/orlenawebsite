<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * Chooses the payment gateway. DOKU_ENABLED=false and DOKU_MODE not "demo" means no online payments:
 * staff confirm orders and record payments with "Mark as paid" (provider "manual", no gateway).
 */
class PaymentGateways
{
    private const BY_PROVIDER = ['doku' => DokuCheckoutGateway::class, 'doku_qris' => DokuQrisGateway::class, 'demo' => DemoGateway::class];

    private const BY_MODE = ['checkout' => DokuCheckoutGateway::class, 'qris' => DokuQrisGateway::class, 'demo' => DemoGateway::class];

    public static function demo(): bool
    {
        return config('services.doku.mode') === 'demo';
    }

    /** Whether confirming an order creates a payment link. */
    public static function enabled(): bool
    {
        return (bool) config('services.doku.enabled') || self::demo();
    }

    /** Gateway for new payment links. */
    public function current(): PaymentGateway
    {
        return app(self::BY_MODE[config('services.doku.mode', 'qris')] ?? DokuQrisGateway::class);
    }

    /** Gateway that created an existing payment; null for manual payments. */
    public function for(Payment $payment): ?PaymentGateway
    {
        $class = self::BY_PROVIDER[$payment->provider] ?? null;

        return $class ? app($class) : null;
    }
}

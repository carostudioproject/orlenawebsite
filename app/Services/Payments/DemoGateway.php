<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * Demo only (DOKU_MODE=demo): the real payment page with a sample QR or BCA Virtual Account that cannot be paid,
 * plus a "simulate payment" button (PaymentPageController::simulate). DOKU is never called. Never use on the live site.
 */
class DemoGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'demo';
    }

    public function create(Payment $payment): array
    {
        $code = $payment->order->order_code;
        $link = ['url' => url('/bayar/'.$code), 'reference' => 'DEMO-'.$payment->id, 'session_id' => 'DEMO-'.$payment->id];
        if ($payment->method === 'va_bca') {
            return [...$link, 'va' => '   19008'.str_pad((string) $payment->id, 10, '0', STR_PAD_LEFT)];
        }

        return [
            ...$link,
            'qr' => '00020101021226590016ID.CO.QRIS.WWW0215DEMO-ORLENA-0000303UMI5204581253033605802ID5906ORLENA6008DENPASAR6213'.substr($code, -10).'6304DEMO',
        ];
    }

    /** Only the simulate button pays a demo link; it still expires locally. */
    public function status(Payment $payment): ?array
    {
        return null;
    }

    public function cancel(Payment $payment): bool
    {
        return true;
    }
}

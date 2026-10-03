<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Doku\DokuException;

/**
 * One way of collecting an online payment. The rest of the app only talks to this interface;
 * PaymentGateways picks the implementation from DOKU_MODE (new links) or from payments.provider (existing ones).
 */
interface PaymentGateway
{
    /** Value stored in payments.provider. */
    public function name(): string;

    /**
     * Creates the link for a payment that is being created.
     *
     * @return array{url: string, token?: string, qr?: string|null, reference?: string|null, session_id?: string|null}
     *
     * @throws DokuException
     */
    public function create(Payment $payment): array;

    /**
     * Current status in the fields ApplyPaymentStatus reads, or null when the provider has nothing (yet).
     *
     * @throws DokuException
     */
    public function status(Payment $payment): ?array;

    /** Best effort: stops an unpaid link from being paid. */
    public function cancel(Payment $payment): bool;
}

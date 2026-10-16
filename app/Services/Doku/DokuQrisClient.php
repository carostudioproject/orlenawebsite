<?php

namespace App\Services\Doku;

/** DOKU QRIS Direct API (SNAP), signed and sent through DokuSnapClient. Production only: the DOKU sandbox has no QRIS. */
class DokuQrisClient
{
    public const GENERATE_PATH = '/snap-adapter/b2b/v1.0/qr/qr-mpm-generate';

    public const QUERY_PATH = '/snap-adapter/b2b/v1.0/qr/qr-mpm-query';

    public const CANCEL_PATH = '/snap-adapter/b2b/v1.0/qr/qr-expire';

    public function __construct(private DokuSnapClient $snap) {}

    /** Returns DOKU's referenceNo and the QR string to render. */
    public function generate(string $partnerReference, int $amount, \DateTimeInterface $validUntil): array
    {
        $response = $this->send(self::GENERATE_PATH, [
            'partnerReferenceNo' => $partnerReference,
            'amount' => ['value' => $amount.'.00', 'currency' => 'IDR'],
            'merchantId' => (string) config('services.doku.qris_merchant_id'),
            'terminalId' => (string) config('services.doku.qris_terminal_id'),
            'validityPeriod' => (new \DateTimeImmutable('@'.$validUntil->getTimestamp()))->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d\TH:i:sP'),
            'additionalInfo' => ['postalCode' => (string) config('services.doku.qris_postal_code'), 'feeType' => '1'],
        ]);
        if (! $this->snap->ok($response) || ! is_string($response->json('qrContent'))) {
            throw new DokuException('DOKU could not create the QRIS'.$this->snap->reason($response));
        }

        return ['reference' => (string) $response->json('referenceNo'), 'qr' => $response->json('qrContent')];
    }

    /**
     * Current status as the fields ApplyPaymentStatus reads. SNAP latestTransactionStatus:
     * 00 success, 01 initiated, 02 paying, 03 pending, 04 refunded, 05 cancelled, 06 failed, 07 not found.
     */
    public function query(string $reference, string $partnerReference): array
    {
        $response = $this->send(self::QUERY_PATH, [
            'originalReferenceNo' => $reference, 'originalPartnerReferenceNo' => $partnerReference,
            'serviceCode' => '47', 'merchantId' => (string) config('services.doku.qris_merchant_id'),
        ]);
        if (! $this->snap->ok($response)) {
            throw new DokuException('Could not read the QRIS status'.$this->snap->reason($response));
        }
        $status = match ((string) $response->json('latestTransactionStatus')) {
            '00' => 'SUCCESS',
            '04' => 'REFUNDED',
            '05' => 'CANCELLED',
            '06' => 'FAILED',
            default => 'PENDING',
        };

        return [
            'order_id' => $partnerReference, 'transaction_status' => $status,
            'gross_amount' => $response->json('amount.value'),
            'payment_type' => 'QRIS'.($response->json('additionalInfo.issuerName') ? '-'.mb_substr((string) $response->json('additionalInfo.issuerName'), 0, 30) : ''),
            'transaction_date' => $response->json('paidTime'),
        ];
    }

    /** Best effort: makes an unpaid QR unusable. */
    public function cancel(string $reference, string $partnerReference): bool
    {
        try {
            return $this->snap->ok($this->send(self::CANCEL_PATH, [
                'partnerReferenceNo' => $partnerReference, 'referenceNo' => $reference,
                'merchantId' => (string) config('services.doku.qris_merchant_id'), 'reason' => 'Order cancelled by Orlena',
            ]));
        } catch (DokuException) {
            return false;
        }
    }

    /** SNAP transactional signature (exposed for tests). */
    public function signature(string $method, string $path, string $token, string $body, string $timestamp): string
    {
        return $this->snap->signature($method, $path, $token, $body, $timestamp);
    }

    private function send(string $path, array $body)
    {
        foreach (['qris_merchant_id', 'qris_terminal_id', 'qris_postal_code'] as $key) {
            if (blank(config('services.doku.'.$key))) {
                throw new DokuException('DOKU QRIS settings are incomplete ('.$key.')');
            }
        }

        return $this->snap->send($path, $body);
    }
}

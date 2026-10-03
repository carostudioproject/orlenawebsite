<?php

namespace App\Services\Doku;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * DOKU QRIS Direct API (SNAP). A B2B access token is requested with an RSA (SHA256withRSA) signature of
 * "clientId|timestamp"; every QRIS call is then signed with HMAC-SHA512 over
 * "METHOD:path:accessToken:lowerhex(sha256(minified body)):timestamp" using the client secret.
 */
class DokuQrisClient
{
    public const GENERATE_PATH = '/snap-adapter/b2b/v1.0/qr/qr-mpm-generate';

    public const QUERY_PATH = '/snap-adapter/b2b/v1.0/qr/qr-mpm-query';

    public const CANCEL_PATH = '/snap-adapter/b2b/v1.0/qr/qr-expire';

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
        if (! $this->ok($response) || ! is_string($response->json('qrContent'))) {
            throw new DokuException('DOKU could not create the QRIS'.$this->reason($response));
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
        if (! $this->ok($response)) {
            throw new DokuException('Could not read the QRIS status'.$this->reason($response));
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
            return $this->ok($this->send(self::CANCEL_PATH, [
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
        $stringToSign = $method.':'.$path.':'.$token.':'.strtolower(hash('sha256', $body)).':'.$timestamp;

        return base64_encode(hash_hmac('sha512', $stringToSign, $this->secret(), true));
    }

    private function send(string $path, array $body): Response
    {
        $token = $this->token();
        $timestamp = $this->timestamp();
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        try {
            return Http::baseUrl($this->base())->acceptJson()->timeout((int) config('services.doku.timeout', 20))->withHeaders([
                'Authorization' => 'Bearer '.$token, 'X-PARTNER-ID' => $this->clientId(), 'X-EXTERNAL-ID' => (string) random_int(100000000, 999999999).time(),
                'X-TIMESTAMP' => $timestamp, 'X-SIGNATURE' => $this->signature('POST', $path, $token, $json, $timestamp), 'CHANNEL-ID' => 'H2H',
            ])->withBody($json, 'application/json')->post($path);
        } catch (ConnectionException) {
            throw new DokuException('DOKU did not respond');
        }
    }

    /** B2B access token, cached until shortly before it expires. */
    private function token(): string
    {
        foreach (['qris_merchant_id', 'qris_terminal_id', 'qris_postal_code'] as $key) {
            if (blank(config('services.doku.'.$key))) {
                throw new DokuException('DOKU QRIS settings are incomplete ('.$key.')');
            }
        }

        return Cache::remember('doku-snap-token:'.md5($this->clientId().$this->base()), 600, function () {
            $timestamp = $this->timestamp();
            $key = openssl_pkey_get_private($this->privateKey());
            if ($key === false || ! openssl_sign($this->clientId().'|'.$timestamp, $signed, $key, OPENSSL_ALGO_SHA256)) {
                throw new DokuException('The DOKU private key could not be read');
            }
            try {
                $response = Http::baseUrl($this->base())->acceptJson()->timeout((int) config('services.doku.timeout', 20))->withHeaders([
                    'X-CLIENT-KEY' => $this->clientId(), 'X-TIMESTAMP' => $timestamp, 'X-SIGNATURE' => base64_encode($signed),
                ])->post((string) config('services.doku.snap_token_path'), ['grantType' => 'client_credentials']);
            } catch (ConnectionException) {
                throw new DokuException('DOKU did not respond');
            }
            if (! $response->successful() || ! is_string($response->json('accessToken'))) {
                throw new DokuException('DOKU refused the access token'.$this->reason($response));
            }

            return $response->json('accessToken');
        });
    }

    private function ok(Response $response): bool
    {
        // SNAP success codes start with "200" (e.g. 2004700).
        return $response->successful() && str_starts_with((string) $response->json('responseCode'), '200');
    }

    private function reason(Response $response): string
    {
        $message = $response->json('responseMessage');

        return ' (HTTP '.$response->status().($response->json('responseCode') ? ' '.$response->json('responseCode') : '').(is_string($message) ? ': '.mb_substr($message, 0, 120) : '').')';
    }

    private function privateKey(): string
    {
        $path = (string) config('services.doku.snap_private_key_path');
        if ($path === '' || ! is_readable($path)) {
            throw new DokuException('The DOKU private key file is missing');
        }

        return (string) file_get_contents($path);
    }

    private function timestamp(): string
    {
        return now('Asia/Jakarta')->format('Y-m-d\TH:i:sP');
    }

    private function clientId(): string
    {
        $id = (string) config('services.doku.client_id');
        if ($id === '' || $this->secret() === '') {
            throw new DokuException('DOKU Client ID or Secret Key is not set');
        }

        return $id;
    }

    private function secret(): string
    {
        return (string) (config('services.doku.snap_client_secret') ?: config('services.doku.secret_key'));
    }

    private function base(): string
    {
        return config('services.doku.is_production') ? 'https://api.doku.com' : 'https://api-sandbox.doku.com';
    }
}

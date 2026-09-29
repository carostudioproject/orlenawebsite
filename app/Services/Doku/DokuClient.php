<?php

namespace App\Services\Doku;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * DOKU Checkout (non-SNAP). Every request and notification is signed with HMAC-SHA256 over
 * Client-Id, Request-Id, Request-Timestamp, Request-Target and (for bodies) a SHA-256 Digest.
 */
class DokuClient
{
    public const CHECKOUT_PATH = '/checkout/v1/payment';

    /** Returns the checkout page URL and token. */
    public function createCheckout(array $body, string $requestId): array
    {
        $response = $this->send('POST', self::CHECKOUT_PATH, $body, $requestId);
        $url = $response->json('response.payment.url');
        if (! $response->successful() || ! is_string($url)) {
            throw new DokuException('DOKU rejected the payment'.$this->reason($response));
        }

        return [
            'url' => $url, 'token' => (string) $response->json('response.payment.token_id'),
            'session_id' => $response->json('response.order.session_id'),
        ];
    }

    /** Normalised status, or null while DOKU has no transaction yet (the customer has not paid or chosen a method). */
    public function status(string $invoiceNumber): ?array
    {
        $response = $this->send('GET', '/orders/v1/status/'.rawurlencode($invoiceNumber));
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new DokuException('Could not read the DOKU status'.$this->reason($response));
        }

        return is_string($response->json('transaction.status')) ? $this->normalize($response->json()) : null;
    }

    /** Best effort: DOKU only cancels unpaid Virtual Account/QRIS checkouts, and only when Order Cancellation is enabled. */
    public function cancel(string $invoiceNumber, string $originalRequestId): bool
    {
        try {
            return $this->send('POST', '/checkout/v3/cancellations', [
                'order' => ['invoice_number' => $invoiceNumber], 'payment' => ['original_request_id' => $originalRequestId], 'note' => 'Order cancelled by Orlena',
            ])->successful();
        } catch (DokuException) {
            return false;
        }
    }

    /** Checks the Signature DOKU puts on an HTTP notification sent to this request's path. */
    public function validNotification(Request $request): bool
    {
        $signature = (string) $request->header('Signature');
        if ($this->secretKey() === '' || $signature === '' || ! hash_equals($this->clientId(), (string) $request->header('Client-Id'))) {
            return false;
        }
        $expected = $this->signature((string) $request->header('Request-Id'), (string) $request->header('Request-Timestamp'), '/'.ltrim($request->path(), '/'), $request->getContent());

        return hash_equals($expected, $signature);
    }

    /** DOKU notification or status body → the fields ApplyPaymentStatus reads. */
    public function normalize(array $data): array
    {
        return [
            'order_id' => data_get($data, 'order.invoice_number'),
            'transaction_status' => strtoupper((string) data_get($data, 'transaction.status')),
            'gross_amount' => data_get($data, 'order.amount'),
            'payment_type' => data_get($data, 'channel.id') ?? data_get($data, 'service.id'),
            'transaction_date' => data_get($data, 'transaction.date'),
        ];
    }

    public function signature(string $requestId, string $timestamp, string $target, ?string $body): string
    {
        $components = 'Client-Id:'.$this->clientId()."\nRequest-Id:".$requestId."\nRequest-Timestamp:".$timestamp."\nRequest-Target:".$target;
        if ($body !== null && $body !== '') {
            $components .= "\nDigest:".base64_encode(hash('sha256', $body, true));
        }

        return 'HMACSHA256='.base64_encode(hash_hmac('sha256', $components, $this->secretKey(), true));
    }

    private function send(string $method, string $path, ?array $body = null, ?string $requestId = null): Response
    {
        if ($this->clientId() === '' || $this->secretKey() === '') {
            throw new DokuException('DOKU Client ID or Secret Key is not set');
        }
        $requestId ??= (string) Str::uuid();
        $timestamp = now('UTC')->format('Y-m-d\TH:i:s\Z');
        // The Digest must be computed over the exact bytes that are sent.
        $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $request = Http::baseUrl($this->base())->acceptJson()->timeout((int) config('services.doku.timeout', 20))->withHeaders([
            'Client-Id' => $this->clientId(), 'Request-Id' => $requestId, 'Request-Timestamp' => $timestamp,
            'Signature' => $this->signature($requestId, $timestamp, $path, $json),
        ]);
        try {
            return $json === null ? $request->get($path) : $request->withBody($json, 'application/json')->send($method, $path);
        } catch (ConnectionException) {
            throw new DokuException('DOKU did not respond');
        }
    }

    private function reason(Response $response): string
    {
        // Provider error text is kept short and never includes credentials.
        $message = collect((array) ($response->json('error_messages') ?? $response->json('message') ?? []))->filter(fn ($m) => is_string($m))->first()
            ?? (is_string($response->json('error.message')) ? $response->json('error.message') : null);

        return ' (HTTP '.$response->status().($message ? ': '.mb_substr($message, 0, 120) : '').')';
    }

    private function clientId(): string
    {
        return (string) config('services.doku.client_id');
    }

    private function secretKey(): string
    {
        return (string) config('services.doku.secret_key');
    }

    private function base(): string
    {
        return config('services.doku.is_production') ? 'https://api.doku.com' : 'https://api-sandbox.doku.com';
    }
}

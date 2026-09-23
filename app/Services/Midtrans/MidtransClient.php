<?php

namespace App\Services\Midtrans;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class MidtransClient
{
    public function createSnap(array $payload): array
    {
        $response = $this->send(fn () => $this->http($this->snapBase())->post('/snap/v1/transactions', $payload));
        if (! $response->successful() || ! is_string($response->json('token')) || ! is_string($response->json('redirect_url'))) {
            throw new MidtransException('Midtrans rejected the payment'.$this->reason($response));
        }

        return ['token' => $response->json('token'), 'redirect_url' => $response->json('redirect_url')];
    }

    /** Returns null when Midtrans has no transaction yet (the customer has not chosen a payment method). */
    public function status(string $providerOrderId): ?array
    {
        $response = $this->send(fn () => $this->http($this->apiBase())->get('/v2/'.rawurlencode($providerOrderId).'/status'));
        if ($response->status() === 404 || $response->json('status_code') === '404') {
            return null;
        }
        if (! $response->successful() || ! is_string($response->json('transaction_status'))) {
            throw new MidtransException('Could not read the Midtrans status'.$this->reason($response));
        }

        return $response->json();
    }

    /** Best effort: closes an unpaid Snap page, falling back to the Core API once a payment method was chosen. */
    public function cancel(string $snapToken, string $providerOrderId): bool
    {
        try {
            if ($this->http($this->snapBase())->post('/snap/v1/transactions/'.rawurlencode($snapToken).'/cancel')->successful()) {
                return true;
            }

            return $this->http($this->apiBase())->post('/v2/'.rawurlencode($providerOrderId).'/cancel')->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function validSignature(array $notification): bool
    {
        $signature = $notification['signature_key'] ?? null;
        $key = $this->serverKey();
        if (! is_string($signature) || $key === '') {
            return false;
        }
        $expected = hash('sha512', ($notification['order_id'] ?? '').($notification['status_code'] ?? '').($notification['gross_amount'] ?? '').$key);

        return hash_equals($expected, $signature);
    }

    private function send(callable $request): Response
    {
        if ($this->serverKey() === '') {
            throw new MidtransException('Midtrans server key is not set');
        }
        try {
            return $request();
        } catch (ConnectionException) {
            throw new MidtransException('Midtrans did not respond');
        }
    }

    private function http(string $base): PendingRequest
    {
        return Http::baseUrl($base)->acceptJson()->asJson()->withBasicAuth($this->serverKey(), '')->timeout(config('services.midtrans.timeout'));
    }

    private function reason(Response $response): string
    {
        // Provider error text is kept short and never includes request credentials.
        $message = collect($response->json('error_messages') ?? [])->filter(fn ($m) => is_string($m))->first();

        return ' (HTTP '.$response->status().($message ? ': '.mb_substr($message, 0, 120) : '').')';
    }

    private function serverKey(): string
    {
        return (string) config('services.midtrans.server_key');
    }

    private function snapBase(): string
    {
        return config('services.midtrans.is_production') ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
    }

    private function apiBase(): string
    {
        return config('services.midtrans.is_production') ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
    }
}

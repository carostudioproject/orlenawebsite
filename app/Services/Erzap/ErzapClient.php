<?php

namespace App\Services\Erzap;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Outbound Erzap calls. Erzap's API documentation and sandbox are not available yet, so the base URL, token
 * and endpoint paths come from config (ERZAP_*). Until ERZAP_BASE_URL and ERZAP_API_TOKEN are set, nothing is sent
 * and syncs wait as "waiting_config". Adjust paths and payload in this class once the documentation is received.
 */
class ErzapClient
{
    public function configured(): bool
    {
        return (bool) config('services.erzap.enabled') && filled(config('services.erzap.base_url')) && filled(config('services.erzap.token'));
    }

    /** Sends a paid order; returns Erzap's reference for the transaction. */
    public function pushTransaction(array $payload): array
    {
        return $this->post(config('services.erzap.paths.transaction'), $payload);
    }

    /** Cancels/voids a transaction sent before (cancelled or refunded after payment). */
    public function cancelTransaction(array $payload): array
    {
        return $this->post(config('services.erzap.paths.transaction_cancel'), $payload);
    }

    private function post(string $path, array $payload): array
    {
        if (! $this->configured()) {
            throw new ErzapException('Erzap is not configured', notConfigured: true);
        }
        try {
            $response = Http::baseUrl(rtrim(config('services.erzap.base_url'), '/'))->withToken(config('services.erzap.token'))
                ->acceptJson()->timeout((int) config('services.erzap.timeout', 20))->post($path, $payload);
        } catch (ConnectionException) {
            throw new ErzapException('Could not reach Erzap');
        }
        if (! $response->successful()) {
            throw new ErzapException('Erzap rejected the request'.$this->reason($response));
        }

        return ['reference' => (string) ($response->json('id') ?? $response->json('data.id') ?? $response->json('reference') ?? ''), 'body' => $response->json() ?? []];
    }

    private function reason(Response $response): string
    {
        $message = $response->json('message') ?? $response->json('error');

        return ' (HTTP '.$response->status().(is_string($message) ? ': '.mb_substr($message, 0, 200) : '').')';
    }
}

<?php

namespace App\Services\Erzap;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends paid orders to Erzap through the OLZAP "simpan_pesanan_penjualan" API.
 * Erzap authenticates with token_erzap inside the body and answers {"status": "1"} on success, "0" with a message on error.
 */
class ErzapClient
{
    /** Settings still missing before orders can be sent (empty when ready). */
    public function missingSettings(): array
    {
        return array_keys(array_filter([
            'ERZAP_ENABLED' => ! config('services.erzap.enabled'),
            'ERZAP_BASE_URL' => blank(config('services.erzap.base_url')),
            'ERZAP_TOKEN' => blank(config('services.erzap.token')),
            'ERZAP_SALES_USER_ID' => blank(config('services.erzap.sales_user_id')),
        ]));
    }

    public function configured(): bool
    {
        return $this->missingSettings() === [];
    }

    /** Sends one order ("shopping_carts" without the token; it is added here so it never reaches logs). */
    public function sendOrder(array $cart): array
    {
        if (! $this->configured()) {
            throw new ErzapException('Erzap is not configured', notConfigured: true);
        }
        $cart['token_erzap'] = config('services.erzap.token');
        try {
            $response = Http::baseUrl(rtrim(config('services.erzap.base_url'), '/'))->acceptJson()->asJson()
                ->timeout((int) config('services.erzap.timeout', 20))->post(config('services.erzap.order_path'), ['shopping_carts' => $cart]);
        } catch (ConnectionException) {
            throw new ErzapException('Could not reach Erzap');
        }
        // The documented response has a "message " key with a trailing space; accept both spellings.
        $body = collect($response->json() ?? [])->mapWithKeys(fn ($value, $key) => [trim((string) $key) => $value])->all();
        if (! $response->successful() || (string) ($body['status'] ?? '') !== '1') {
            throw new ErzapException('Erzap rejected the order'.$this->reason($response, $body));
        }

        return ['body' => $body];
    }

    private function reason(Response $response, array $body): string
    {
        $message = $body['message'] ?? $body['notice'] ?? $body['error'] ?? null;

        return ' (HTTP '.$response->status().(is_string($message) && $message !== '' ? ': '.mb_substr($message, 0, 200) : '').')';
    }
}

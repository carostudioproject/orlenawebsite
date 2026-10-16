<?php

namespace App\Services\Doku;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * DOKU Direct API (SNAP) transport shared by QRIS and Virtual Account. A B2B access token is requested with an RSA
 * (SHA256withRSA) signature of "clientId|timestamp"; every call is then signed with HMAC-SHA512 over
 * "METHOD:path:accessToken:lowerhex(sha256(minified body)):timestamp" using the client secret.
 */
class DokuSnapClient
{
    public function send(string $path, array $body, string $method = 'POST'): Response
    {
        $token = $this->token();
        $timestamp = $this->timestamp();
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        try {
            return Http::baseUrl($this->base())->acceptJson()->timeout((int) config('services.doku.timeout', 20))->withHeaders([
                'Authorization' => 'Bearer '.$token, 'X-PARTNER-ID' => $this->clientId(), 'X-EXTERNAL-ID' => (string) random_int(100000000, 999999999).time(),
                'X-TIMESTAMP' => $timestamp, 'X-SIGNATURE' => $this->signature($method, $path, $token, $json, $timestamp), 'CHANNEL-ID' => 'H2H',
            ])->withBody($json, 'application/json')->send($method, $path);
        } catch (ConnectionException) {
            throw new DokuException('DOKU did not respond');
        }
    }

    /** SNAP transactional signature (exposed for tests). */
    public function signature(string $method, string $path, string $token, string $body, string $timestamp): string
    {
        $stringToSign = $method.':'.$path.':'.$token.':'.strtolower(hash('sha256', $body)).':'.$timestamp;

        return base64_encode(hash_hmac('sha512', $stringToSign, $this->secret(), true));
    }

    /**
     * Checks a notification DOKU signed with its private key (SHA256withRSA over
     * "POST:path:lowerhex(sha256(minified body)):timestamp"). False when DOKU's public key is not configured.
     */
    public function verifyNotification(string $path, string $body, string $timestamp, string $signature): bool
    {
        $file = (string) config('services.doku.snap_doku_public_key_path');
        if ($file === '' || ! is_readable($file) || $signature === '' || $timestamp === '') {
            return false;
        }
        $key = openssl_pkey_get_public((string) file_get_contents($file));
        $minified = json_encode(json_decode($body, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stringToSign = 'POST:'.$path.':'.strtolower(hash('sha256', (string) $minified)).':'.$timestamp;

        return $key !== false && openssl_verify($stringToSign, (string) base64_decode($signature, true), $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public function ok(Response $response): bool
    {
        // SNAP success codes start with "200" (e.g. 2004700, 2002700).
        return $response->successful() && str_starts_with((string) $response->json('responseCode'), '200');
    }

    public function reason(Response $response): string
    {
        $message = $response->json('responseMessage');

        return ' (HTTP '.$response->status().($response->json('responseCode') ? ' '.$response->json('responseCode') : '').(is_string($message) ? ': '.mb_substr($message, 0, 120) : '').')';
    }

    /** B2B access token, cached until shortly before it expires. */
    private function token(): string
    {
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

    private function privateKey(): string
    {
        $path = (string) config('services.doku.snap_private_key_path');
        if ($path === '' || ! is_readable($path)) {
            throw new DokuException('The DOKU private key file is missing');
        }

        return (string) file_get_contents($path);
    }

    public function timestamp(): string
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

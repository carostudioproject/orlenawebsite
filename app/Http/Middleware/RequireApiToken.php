<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token check for the REST API. Tokens come from API_TOKENS.
 * With no token configured the endpoint stays closed (503), so nothing is exposed by default.
 */
class RequireApiToken
{
    public function handle(Request $request, Closure $next, string $scope = 'api'): Response
    {
        $tokens = config('services.api.tokens', []);
        if ($tokens === []) {
            return response()->json(['message' => 'API belum diaktifkan.'], 503);
        }
        $given = (string) $request->bearerToken();
        foreach ($tokens as $token) {
            if ($given !== '' && hash_equals((string) $token, $given)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Token tidak valid.'], 401);
    }
}

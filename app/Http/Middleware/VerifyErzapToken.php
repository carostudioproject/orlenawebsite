<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Erzap → website calls carry the account token in X-Erzap-Token; anything else gets 401 (Erzap API v1.2, 1.4). */
class VerifyErzapToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.erzap.inbound_token');
        $given = (string) $request->header('X-Erzap-Token');
        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['olzap' => ['status' => '0', 'msg' => 'Unauthorized']], 401);
        }

        return $next($request);
    }
}

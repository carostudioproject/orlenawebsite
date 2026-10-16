<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Doku\DokuSnapClient;
use Illuminate\Http\Request;

/**
 * DOKU's "Token URL" (DOKU dashboard → API Keys → Pengaturan SNAP): DOKU gets a B2B access token here before it sends
 * a payment notification, then signs the notification with it. Set it to {APP_URL}/authorization/v1/access-token/b2b.
 */
class DokuSnapTokenController extends Controller
{
    public function __invoke(Request $request, DokuSnapClient $snap)
    {
        $token = $snap->inboundToken((string) $request->header('X-CLIENT-KEY'), (string) $request->header('X-TIMESTAMP'), (string) $request->header('X-SIGNATURE'));
        if (! $token) {
            return response()->json(['responseCode' => '4017300', 'responseMessage' => 'Unauthorized. Signature'], 401);
        }

        return response()->json([
            'responseCode' => '2007300', 'responseMessage' => 'Successful',
            'accessToken' => $token, 'tokenType' => 'Bearer', 'expiresIn' => (string) DokuSnapClient::INBOUND_TOKEN_SECONDS,
        ]);
    }
}

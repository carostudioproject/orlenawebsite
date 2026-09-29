<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Payments\ApplyPaymentStatus;
use App\Http\Controllers\Controller;
use App\Services\Doku\DokuClient;
use Illuminate\Http\Request;

/** DOKU HTTP Notification. Set the Notification URL in the DOKU Back Office to {APP_URL}/webhooks/doku. */
class DokuWebhookController extends Controller
{
    public function __invoke(Request $request, DokuClient $doku, ApplyPaymentStatus $apply)
    {
        if (! $doku->validNotification($request)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        // Any 2xx stops DOKU retries; unknown or duplicate events are recorded, not retried.
        return response()->json(['status' => $apply->handle($doku->normalize($request->json()->all()), 'webhook')]);
    }
}

<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Payments\ApplyPaymentStatus;
use App\Http\Controllers\Controller;
use App\Services\Midtrans\MidtransClient;
use Illuminate\Http\Request;

class MidtransWebhookController extends Controller
{
    public function __invoke(Request $request, MidtransClient $midtrans, ApplyPaymentStatus $apply)
    {
        $notification = $request->all();
        if (! $midtrans->validSignature($notification)) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        // Any 2xx stops Midtrans retries; unknown or duplicate events are recorded, not retried.
        return response()->json(['status' => $apply->handle($notification, 'webhook')]);
    }
}

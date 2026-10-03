<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Payments\ReconcilePayment;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Doku\DokuException;
use Illuminate\Http\Request;

/**
 * DOKU QRIS payment notification. The body is only used to find our payment; its status is always read back
 * from DOKU (QRIS query), so a forged notification cannot mark an order as paid.
 * Set the QRIS Notification URL in DOKU to {APP_URL}/webhooks/doku-qris.
 */
class DokuQrisWebhookController extends Controller
{
    public function __invoke(Request $request, ReconcilePayment $reconcile)
    {
        $references = array_filter(array_map('strval', [
            $request->input('originalPartnerReferenceNo'), $request->input('partnerReferenceNo'), $request->input('invoice'),
            $request->input('originalReferenceNo'), $request->input('referenceNo'),
        ]));
        $payment = $references ? Payment::where('provider', 'doku_qris')
            ->where(fn ($query) => $query->whereIn('provider_order_id', $references)->orWhereIn('provider_request_id', $references))->first() : null;
        $result = 'unknown_payment';
        if ($payment) {
            try {
                $result = $reconcile->handle($payment);
            } catch (DokuException) {
                $result = 'check_failed';
            }
        }

        // SNAP-style acknowledgement; any 2xx stops DOKU retries.
        return response()->json(['responseCode' => '2005200', 'responseMessage' => 'Successful', 'status' => $result]);
    }
}

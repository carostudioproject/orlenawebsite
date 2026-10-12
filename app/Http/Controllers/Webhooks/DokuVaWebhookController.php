<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Payments\ApplyPaymentStatus;
use App\Actions\Payments\ReconcilePayment;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Doku\DokuException;
use App\Services\Doku\DokuSnapClient;
use Illuminate\Http\Request;

/**
 * DOKU BCA Virtual Account payment notification (SNAP). Trusted only when DOKU's signature verifies with DOKU's
 * public key (DOKU_SNAP_DOKU_PUBLIC_KEY_PATH); otherwise the body only identifies our payment and its status is read
 * back from DOKU, so a forged notification cannot mark an order as paid.
 * Set the VA Notification URL in DOKU to {APP_URL}/webhooks/doku-va.
 */
class DokuVaWebhookController extends Controller
{
    public function __invoke(Request $request, DokuSnapClient $snap, ApplyPaymentStatus $apply, ReconcilePayment $reconcile)
    {
        $trxId = (string) ($request->input('trxId') ?? $request->input('additionalInfo.trxId') ?? '');
        $payment = $trxId !== '' ? Payment::where('provider', 'doku_va')->where('provider_order_id', $trxId)->first() : null;
        $result = 'unknown_payment';
        if ($payment) {
            $signed = $snap->verifyNotification('/'.$request->path(), $request->getContent(), (string) $request->header('X-TIMESTAMP'), (string) $request->header('X-SIGNATURE'));
            $sameVa = trim((string) $request->input('virtualAccountNo')) === trim((string) $payment->va_number);
            try {
                $result = $signed && $sameVa
                    ? $apply->handle([
                        'order_id' => $payment->provider_order_id, 'transaction_status' => 'SUCCESS', 'gross_amount' => $request->input('paidAmount.value'),
                        'payment_type' => 'VA-BCA', 'transaction_date' => $request->input('trxDateTime') ?? $request->input('paymentRequestId'),
                    ], 'notification')
                    : $reconcile->handle($payment);
            } catch (DokuException) {
                $result = 'check_failed';
            }
        }

        // SNAP VA acknowledgement; any 2xx stops DOKU retries.
        return response()->json([
            'responseCode' => '2002500', 'responseMessage' => 'Success',
            'virtualAccountData' => $request->only('partnerServiceId', 'customerNo', 'virtualAccountNo', 'virtualAccountName', 'paymentRequestId', 'paidAmount'),
            'status' => $result,
        ]);
    }
}

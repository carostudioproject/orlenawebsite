<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Integrations\ErzapSync;
use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\ReviewOrder;
use App\Actions\Payments\CreatePaymentLink;
use App\Actions\Payments\ReconcilePayment;
use App\Actions\Payments\RecordManualPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewOrderRequest;
use App\Models\IntegrationSync;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Doku\DokuException;
use App\Services\Payments\PaymentGateways;
use App\Support\PreorderDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OrderController extends Controller
{
    private const ORDER_STATUSES = 'pending_review,confirmed,processing,ready,delivering,completed,cancelled';

    private const PAYMENT_STATUSES = 'not_created,pending,paid,failed,expired,cancelled,refunded';

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:'.self::ORDER_STATUSES], 'payment' => ['nullable', 'in:'.self::PAYMENT_STATUSES],
        ]);
        $orders = Order::with('customer:id,name,whatsapp')->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(fn ($q) => $q->where('order_code', 'like', '%'.$search.'%')->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$search.'%')->orWhere('whatsapp', 'like', '%'.$search.'%')));
        })->when($filters['date'] ?? null, fn ($q, $date) => $q->whereDate('requested_date', $date))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('order_status', $status))
            ->when($filters['payment'] ?? null, fn ($q, $payment) => $q->where('payment_status', $payment))
            ->latest('id')->paginate(10)->withQueryString();
        Inertia::encryptHistory();

        return Inertia::render('Admin/Orders/Index', compact('orders', 'filters'));
    }

    public function show(Order $order)
    {
        Inertia::encryptHistory();

        $reviews = DB::table('order_reviews')->leftJoin('users', 'users.id', '=', 'order_reviews.actor_id')
            ->where('order_id', $order->id)->select('order_reviews.*', 'users.name as actor_name')
            ->orderByDesc('order_reviews.id')->paginate(10);
        $history = DB::table('order_status_histories')->leftJoin('users', 'users.id', '=', 'order_status_histories.actor_id')
            ->where('order_id', $order->id)->select('order_status_histories.*', 'users.name as actor_name')
            ->orderByDesc('order_status_histories.id')->limit(50)->get();
        $payments = $order->payments()->with('creator:id,name')->orderByDesc('attempt')->get();

        return Inertia::render('Admin/Orders/Show', [
            'order' => $order->load('customer', 'items'), 'reviews' => $reviews, 'history' => $history, 'payments' => $payments,
            'additions' => $order->additions()->latest('id')->get(['id', 'items', 'subtotal_added', 'previous_total', 'new_total', 'created_at']),
            'canCancelPaid' => request()->user()->can('cancel-paid-orders'),
            'dokuEnabled' => PaymentGateways::enabled(), 'manualMethods' => RecordManualPayment::METHODS,
            // Staff check capacity by hand; this only warns when the date is busy or closed.
            'dayLoad' => [
                'orders' => Order::whereDate('requested_date', $order->requested_date)->where('order_status', '!=', 'cancelled')->count(),
                'capacity' => app(PreorderDate::class)->dailyCapacity(), 'closed' => app(PreorderDate::class)->closedReason($order->requested_date->toDateString(), english: true),
            ],
            'erzapSync' => IntegrationSync::where('provider', 'erzap')->where('subject_type', 'orders')->where('subject_id', $order->id)->latest('id')
                ->first(['id', 'type', 'status', 'attempts', 'last_error', 'synced_at', 'next_attempt_at', 'external_ref']),
        ]);
    }

    public function review(ReviewOrderRequest $request, Order $order, ReviewOrder $review)
    {
        $review->handle($order, $request->validated(), $request->user()->id);

        return redirect('/admin/orders/'.$order->id)->with('success', 'Review saved.');
    }

    public function confirm(Request $request, Order $order, CreatePaymentLink $links)
    {
        $data = $request->validate(['review_version' => ['required', 'integer', 'min:0']]);

        $payment = $links->confirm($order, (int) $data['review_version'], $request->user()->id);
        if (! $payment) {
            return redirect('/admin/orders/'.$order->id)->with('success', 'Order confirmed. Online payment (DOKU) is off: send the bank details to the customer and press "Mark as paid" once the money arrives.');
        }

        return $this->paymentResult($order, $payment, 'Order confirmed and the payment link is ready to send.');
    }

    public function markPaid(Request $request, Order $order, RecordManualPayment $record, ErzapSync $erzap)
    {
        $data = $request->validate(['method' => ['required', Rule::in(array_keys(RecordManualPayment::METHODS))], 'note' => ['nullable', 'string', 'max:500']]);
        $record->handle($order, $data['method'], $data['note'] ?? null, $request->user()->id);

        // Send to Erzap right away; if that is not possible yet, the 5-minute schedule keeps retrying.
        $status = $erzap->sendNow($order, $request->user()->id);
        $message = match ($status) {
            'synced' => 'Payment recorded. The order is paid and was sent to Erzap.',
            'failed' => 'Payment recorded. Sending to Erzap failed ('.IntegrationSync::where('subject_type', 'orders')->where('subject_id', $order->id)->latest('id')->value('last_error').'); it will retry automatically, or use Resend.',
            'needs_mapping' => 'Payment recorded. Not sent to Erzap yet: complete the barcode/outlet mapping.',
            'waiting_config' => 'Payment recorded. Not sent to Erzap yet: the Erzap settings are not complete.',
            default => 'Payment recorded. The order is paid.',
        };

        return redirect('/admin/orders/'.$order->id)->with('success', $message);
    }

    public function retryPayment(Request $request, Order $order, CreatePaymentLink $links)
    {
        return $this->paymentResult($order, $links->retry($order, $request->user()->id), 'Payment link created.');
    }

    public function renewPayment(Request $request, Order $order, CreatePaymentLink $links)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Write why a new link is needed.']);

        return $this->paymentResult($order, $links->renew($order, $data['reason'], $request->user()->id), 'New payment link created.');
    }

    public function checkPayment(Order $order, Payment $payment, ReconcilePayment $reconcile)
    {
        abort_unless($payment->order_id === $order->id, 404);
        try {
            $result = $reconcile->handle($payment);
        } catch (DokuException $e) {
            return back()->withErrors(['payment' => $e->getMessage().'. Try again in a moment.']);
        }
        $messages = [
            'applied' => 'Payment status updated from DOKU.', 'not_started' => 'The customer has not paid on the DOKU page yet.',
            'amount_mismatch' => 'The DOKU amount does not match. Check the transaction manually.',
        ];

        return back()->with('success', $messages[$result] ?? 'Payment status already matches DOKU.');
    }

    public function advance(Request $request, Order $order, ChangeOrderStatus $status)
    {
        $data = $request->validate(['from' => ['required', 'in:'.self::ORDER_STATUSES], 'to' => ['required', 'in:processing,ready,delivering,completed']]);
        $status->advance($order, $data['from'], $data['to'], $request->user());

        return back()->with('success', 'Order status updated.');
    }

    public function cancel(Request $request, Order $order, ChangeOrderStatus $status)
    {
        $data = $request->validate(
            ['from' => ['required', 'in:'.self::ORDER_STATUSES], 'cancel_reason' => ['required', 'string', 'max:1000']],
            ['cancel_reason.required' => 'Write the cancellation reason.'],
        );
        $closed = $status->cancel($order, $data['from'], $data['cancel_reason'], $request->user());

        return $closed ? back()->with('success', 'Order cancelled.')
            : back()->withErrors(['payment' => 'Order cancelled, but the DOKU link could not be closed automatically (DOKU only cancels unpaid bank transfer and QRIS checkouts). Watch for incoming payments; if the customer still pays, refund manually.']);
    }

    private function paymentResult(Order $order, Payment $payment, string $success)
    {
        if ($payment->status === 'creation_failed') {
            return redirect('/admin/orders/'.$order->id)->withErrors(['payment' => 'The payment link could not be created: '.$payment->last_error.'. Use the retry button.']);
        }

        return redirect('/admin/orders/'.$order->id)->with('success', $success);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Support\TableSort;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Every payment attempt (DOKU) across orders; changes still happen on the order page. */
class PaymentController extends Controller
{
    private const STATUSES = ['creating', 'creation_failed', 'pending', 'paid', 'failed', 'expired', 'cancelled', 'refunded'];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(self::STATUSES)],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        Inertia::encryptHistory();
        $query = Payment::with('order:id,order_code,customer_id,order_status', 'order.customer:id,name,whatsapp', 'creator:id,name')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('provider_order_id', 'like', '%'.$search.'%')
                ->orWhere('transaction_id', 'like', '%'.$search.'%')
                ->orWhereHas('order', fn ($o) => $o->where('order_code', 'like', '%'.$search.'%')->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$search.'%')))))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'));
        $sort = TableSort::apply($query, $request, [
            'amount' => 'amount', 'status' => 'status', 'method' => 'method', 'time' => 'created_at',
            'order' => fn ($q, $dir) => $q->orderBy(Order::select('order_code')->whereColumn('orders.id', 'payments.order_id'), $dir),
        ]);
        $payments = ($sort['sort'] ? $query : $query->latest('id'))->paginate(10)->withQueryString();

        return Inertia::render('Admin/Payments/Index', [
            'payments' => $payments, 'filters' => $filters, 'statuses' => self::STATUSES, 'sort' => $sort,
            'totals' => [
                'paid_today' => (int) Payment::where('status', 'paid')->where('paid_at', '>=', now()->startOfDay())->sum('amount'),
                'pending' => Payment::where('status', 'pending')->count(),
                'pending_amount' => (int) Payment::where('status', 'pending')->sum('amount'),
                'failed_week' => Payment::whereIn('status', ['failed', 'creation_failed'])->where('updated_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }
}

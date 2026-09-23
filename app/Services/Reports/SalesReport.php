<?php

namespace App\Services\Reports;

use App\Models\Outlet;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sales and performance figures from data recorded in the Orlena system (not an accounting ledger).
 *
 * Revenue counts orders whose payment was received in the period (by payment time, WITA), excluding orders
 * cancelled or refunded after payment; those are listed separately. With a product filter, sales figures
 * cover that product's lines only, and transactions are the paid orders containing it.
 */
class SalesReport
{
    public const ORDER_STATUSES = ['pending_review', 'confirmed', 'processing', 'ready', 'delivering', 'completed', 'cancelled'];

    public static function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'], 'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'status' => ['nullable', 'in:'.implode(',', self::ORDER_STATUSES)],
        ];
    }

    /** Defaults to the current month; the range is capped at 366 days to keep exports reasonable. */
    public static function normalize(array $filters): array
    {
        $to = CarbonImmutable::parse($filters['to'] ?? now()->toDateString());
        $from = CarbonImmutable::parse($filters['from'] ?? $to->startOfMonth()->toDateString());
        if ($from->diffInDays($to) > 365) {
            $from = $to->subDays(365);
        }

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'outlet_id' => isset($filters['outlet_id']) ? (int) $filters['outlet_id'] : null,
            'product_id' => isset($filters['product_id']) ? (int) $filters['product_id'] : null,
            'status' => $filters['status'] ?? null,
        ];
    }

    public function build(array $filters): array
    {
        $f = self::normalize($filters);
        $paid = $this->paidOrders($f);
        $items = $this->items($f, (clone $paid)->where('o.payment_status', 'paid')->where('o.order_status', '!=', 'cancelled'));

        $revenueOrders = (clone $paid)->where('o.payment_status', 'paid')->where('o.order_status', '!=', 'cancelled');
        $orderTotals = (clone $revenueOrders)->selectRaw('COUNT(*) as transactions, COALESCE(SUM(o.total), 0) as revenue, COALESCE(SUM(o.subtotal), 0) as product_sales, COALESCE(SUM(COALESCE(o.delivery_fee, 0)), 0) as delivery_fees')->first();
        $itemTotals = (clone $items)->selectRaw('COALESCE(SUM(i.quantity), 0) as quantity, COALESCE(SUM(i.subtotal), 0) as sales')->first();
        $voided = (clone $paid)->where(fn ($q) => $q->where('o.order_status', 'cancelled')->orWhere('o.payment_status', 'refunded'))
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(o.total), 0) as amount')->first();

        // With a product filter, revenue is that product's sales; otherwise the full paid total incl. delivery.
        $revenue = $f['product_id'] ? (int) $itemTotals->sales : (int) $orderTotals->revenue;
        $transactions = (int) $orderTotals->transactions;
        $daily = (clone $revenueOrders)->selectRaw('DATE(p.paid_at) as day, COUNT(*) as transactions, SUM(o.total) as revenue')->groupBy('day')->get()->keyBy('day');
        $dailyItems = $f['product_id'] ? (clone $items)->selectRaw('DATE(p.paid_at) as day, SUM(i.subtotal) as sales')->groupBy('day')->pluck('sales', 'day') : null;
        $days = [];
        for ($day = CarbonImmutable::parse($f['from']); $day->lte(CarbonImmutable::parse($f['to'])); $day = $day->addDay()) {
            $key = $day->toDateString();
            $days[] = ['date' => $key, 'transactions' => (int) ($daily[$key]->transactions ?? 0), 'revenue' => (int) ($dailyItems ? ($dailyItems[$key] ?? 0) : ($daily[$key]->revenue ?? 0))];
        }

        $byOutlet = $f['product_id']
            ? (clone $items)->selectRaw('o.outlet_id, o.outlet_name_snapshot as outlet, COUNT(DISTINCT o.id) as transactions, SUM(i.subtotal) as revenue')->groupBy('o.outlet_id', 'o.outlet_name_snapshot')->orderByDesc('revenue')->get()
            : (clone $revenueOrders)->selectRaw('o.outlet_id, o.outlet_name_snapshot as outlet, COUNT(*) as transactions, SUM(o.total) as revenue')->groupBy('o.outlet_id', 'o.outlet_name_snapshot')->orderByDesc('revenue')->get();

        $products = (clone $items)->selectRaw('i.product_id, i.product_name_snapshot as name, i.variant_snapshot as variant, i.category_snapshot as category, SUM(i.quantity) as quantity, SUM(i.subtotal) as revenue, COUNT(DISTINCT o.id) as orders')
            ->groupBy('i.product_id', 'i.product_name_snapshot', 'i.variant_snapshot', 'i.category_snapshot')->orderByDesc('quantity')->orderByDesc('revenue')->get();

        // Status summary covers orders placed in the period (by order date), so unpaid requests show up too.
        $placed = DB::table('orders as o')->whereBetween('o.created_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
            ->when($f['outlet_id'], fn ($q, $id) => $q->where('o.outlet_id', $id))
            ->when($f['status'], fn ($q, $status) => $q->where('o.order_status', $status))
            ->when($f['product_id'], fn ($q, $id) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('order_items as x')->whereColumn('x.order_id', 'o.id')->where('x.product_id', $id)));

        return [
            'filters' => $f,
            'labels' => [
                'outlet' => $f['outlet_id'] ? Outlet::whereKey($f['outlet_id'])->value('name') : null,
                'product' => $f['product_id'] ? Product::find($f['product_id'])?->label() : null,
            ],
            'summary' => [
                'revenue' => $revenue, 'transactions' => $transactions, 'average' => $transactions ? intdiv($revenue, $transactions) : 0,
                'product_sales' => $f['product_id'] ? (int) $itemTotals->sales : (int) $orderTotals->product_sales,
                'delivery_fees' => $f['product_id'] ? null : (int) $orderTotals->delivery_fees,
                'items_sold' => (int) $itemTotals->quantity,
                'voided_orders' => (int) $voided->orders, 'voided_amount' => (int) $voided->amount,
            ],
            'daily' => $days,
            'outlets' => $byOutlet->map(fn ($row) => ['outlet_id' => $row->outlet_id, 'outlet' => $row->outlet, 'transactions' => (int) $row->transactions, 'revenue' => (int) $row->revenue])->values()->all(),
            'products' => $products->map(fn ($row) => [
                'product_id' => $row->product_id, 'name' => $row->name, 'variant' => $row->variant, 'category' => $row->category,
                'quantity' => (int) $row->quantity, 'revenue' => (int) $row->revenue, 'orders' => (int) $row->orders,
            ])->values()->all(),
            'order_statuses' => (clone $placed)->selectRaw('o.order_status as status, COUNT(*) as total')->groupBy('o.order_status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all(),
            'payment_statuses' => (clone $placed)->selectRaw('o.payment_status as status, COUNT(*) as total')->groupBy('o.payment_status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all(),
            'placed_orders' => (clone $placed)->count(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** Orders with a received payment in the period, joined to that payment's time. */
    private function paidOrders(array $f): Builder
    {
        $payments = DB::table('payments')->whereNotNull('paid_at')->selectRaw('order_id, MAX(paid_at) as paid_at')->groupBy('order_id');

        return DB::table('orders as o')->joinSub($payments, 'p', 'p.order_id', '=', 'o.id')
            ->whereBetween('p.paid_at', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
            ->whereIn('o.payment_status', ['paid', 'refunded'])
            ->when($f['outlet_id'], fn ($q, $id) => $q->where('o.outlet_id', $id))
            ->when($f['status'], fn ($q, $status) => $q->where('o.order_status', $status))
            ->when($f['product_id'], fn ($q, $id) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('order_items as x')->whereColumn('x.order_id', 'o.id')->where('x.product_id', $id)));
    }

    private function items(array $f, Builder $orders): Builder
    {
        return $orders->join('order_items as i', 'i.order_id', '=', 'o.id')->when($f['product_id'], fn ($q, $id) => $q->where('i.product_id', $id));
    }
}

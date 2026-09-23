<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Reports\SalesReport;
use Illuminate\Http\Request;

/** Token-protected, read-only order and report data for integrations. Customer addresses and emails are never included. */
class OrderApiController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', SalesReport::ORDER_STATUSES)],
            'payment_status' => ['nullable', 'in:not_created,creating,creation_failed,pending,paid,failed,expired,cancelled,refunded'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $orders = Order::with('items', 'customer:id,name')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('order_status', $status))
            ->when($filters['payment_status'] ?? null, fn ($q, $status) => $q->where('payment_status', $status))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('requested_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('requested_date', '<=', $date))
            ->when($filters['updated_since'] ?? null, fn ($q, $since) => $q->where('updated_at', '>=', $since))
            ->latest('id')->paginate($filters['per_page'] ?? 25)->withQueryString();
        $orders->getCollection()->transform(fn (Order $order) => $this->present($order));

        return $orders;
    }

    public function show(string $code)
    {
        $order = Order::with('items', 'customer:id,name', 'payments')->where('order_code', $code)->firstOrFail();

        return ['data' => [...$this->present($order), 'payments' => $order->payments->map->only('attempt', 'amount', 'status', 'payment_type', 'paid_at', 'expires_at')]];
    }

    public function report(Request $request, SalesReport $report)
    {
        $data = $report->build($request->validate(SalesReport::rules()));

        return ['data' => [...$data, 'products' => array_slice($data['products'], 0, 50)]];
    }

    private function present(Order $order): array
    {
        return [
            'order_code' => $order->order_code, 'customer_name' => $order->customer->name,
            'outlet_id' => $order->outlet_id, 'outlet_name' => $order->outlet_name_snapshot, 'fulfillment_method' => $order->fulfillment_method,
            'requested_date' => $order->requested_date->toDateString(), 'requested_time' => $order->requested_time ? substr($order->requested_time, 0, 5) : null,
            'subtotal' => $order->subtotal, 'delivery_fee' => $order->delivery_fee, 'total' => $order->total,
            'order_status' => $order->order_status, 'payment_status' => $order->payment_status,
            'items' => $order->items->map(fn ($item) => [
                'product_id' => $item->product_id, 'sku' => $item->sku_snapshot, 'name' => $item->product_name_snapshot, 'variant' => $item->variant_snapshot,
                'category' => $item->category_snapshot, 'unit_price' => $item->unit_price_snapshot, 'quantity' => $item->quantity, 'subtotal' => $item->subtotal,
            ])->values(),
            'created_at' => $order->created_at?->toIso8601String(), 'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }
}

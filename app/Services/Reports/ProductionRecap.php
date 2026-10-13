<?php

namespace App\Services\Reports;

use App\Models\Order;
use App\Models\Setting;
use App\Support\PreorderDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Daily production recap for the central kitchen (CK): paid orders for one pickup/delivery date.
 * Final once that date's H-1 cutoff has passed (Rules Order Website: recap sent to CK at 19.00).
 * "To bake" counts everything the kitchen makes: regular products plus the contents chosen in hampers and boxes;
 * the hampers/boxes themselves are listed separately as packages.
 */
class ProductionRecap
{
    public function __construct(private PreorderDate $dates) {}

    public function build(string $date): array
    {
        $orders = Order::with('items', 'customer:id,name')->whereDate('requested_date', $date)
            ->where('payment_status', 'paid')->where('order_status', '!=', 'cancelled')
            ->orderBy('outlet_name_snapshot')->orderBy('requested_time')->orderBy('id')->get();
        $cutoff = $this->dates->cutoff($date);

        $lines = $orders->flatMap(function (Order $order) {
            $parents = $order->items->whereNotNull('parent_item_id')->pluck('parent_item_id')->unique()->all();

            return $order->items->map(fn ($item) => [
                'outlet' => $order->outlet_name_snapshot, 'name' => $item->product_name_snapshot, 'variant' => $item->variant_snapshot,
                'category' => $item->category_snapshot, 'quantity' => $item->quantity, 'package' => in_array($item->id, $parents, true),
            ]);
        });
        $outlets = $orders->pluck('outlet_name_snapshot')->unique()->values()->all();

        return [
            'date' => $date,
            'final' => CarbonImmutable::now('Asia/Makassar')->greaterThanOrEqualTo($cutoff),
            'cutoff' => $cutoff->toIso8601String(),
            'outlets' => $outlets,
            'summary' => ['orders' => $orders->count(), 'pickup' => $orders->where('fulfillment_method', 'pickup')->count(), 'delivery' => $orders->where('fulfillment_method', 'delivery')->count(),
                'pieces' => (int) $lines->where('package', false)->sum('quantity')],
            'products' => $this->totals($lines->where('package', false), $outlets),
            'packages' => $this->totals($lines->where('package', true), $outlets),
            // Confirmed for this date but not paid yet: not in the recap, shown so the kitchen knows more may come.
            'unpaid' => Order::whereDate('requested_date', $date)->where('order_status', 'confirmed')->where('payment_status', '!=', 'paid')->count(),
            'orders' => $orders->groupBy('outlet_name_snapshot')->map(fn (Collection $group, string $outlet) => [
                'outlet' => $outlet,
                'orders' => $group->map(fn (Order $order) => [
                    'code' => $order->order_code, 'time' => $order->requested_time ? substr($order->requested_time, 0, 5) : null,
                    'method' => $order->fulfillment_method, 'customer' => $order->customer?->name, 'note' => $order->customer_note, 'card' => $order->card_message,
                    'items' => $order->items->whereNull('parent_item_id')->map(fn ($item) => [
                        'label' => $item->quantity.'× '.$item->product_name_snapshot.($item->variant_snapshot ? ' ('.$item->variant_snapshot.')' : ''),
                        'contents' => $order->items->where('parent_item_id', $item->id)
                            ->map(fn ($content) => $content->quantity.'× '.$content->product_name_snapshot.($content->variant_snapshot ? ' ('.$content->variant_snapshot.')' : ''))->values()->all(),
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** WhatsApp text of the recap: totals to bake, then per outlet. */
    public function whatsappText(array $recap): string
    {
        $day = CarbonImmutable::parse($recap['date'])->locale('id')->translatedFormat('l, j F Y');
        $label = fn (array $row) => $row['quantity'].'x '.$row['name'].($row['variant'] ? ' ('.$row['variant'].')' : '');
        $lines = [
            '*Rekap produksi Orlena*',
            $day.' · '.($recap['final'] ? 'FINAL' : 'SEMENTARA (belum lewat cut-off)'),
            'Pesanan lunas: '.$recap['summary']['orders'].' (pickup '.$recap['summary']['pickup'].', delivery '.$recap['summary']['delivery'].')',
            '',
            '*Total produksi*',
            ...array_map(fn ($row) => '- '.$label($row), $recap['products']),
        ];
        if ($recap['packages']) {
            array_push($lines, '', '*Kemasan hampers/box*', ...array_map(fn ($row) => '- '.$label($row), $recap['packages']));
        }
        foreach ($recap['outlets'] as $outlet) {
            $rows = array_filter($recap['products'], fn ($row) => ($row['by_outlet'][$outlet] ?? 0) > 0);
            $lines[] = '';
            $lines[] = '*'.$outlet.'*';
            foreach ($rows as $row) {
                $lines[] = '- '.$label([...$row, 'quantity' => $row['by_outlet'][$outlet]]);
            }
        }
        if ($recap['unpaid']) {
            array_push($lines, '', '_'.$recap['unpaid'].' pesanan untuk tanggal ini belum lunas dan belum termasuk._');
        }

        return implode("\n", $lines);
    }

    /** wa.me link to the kitchen's number (PO schedule settings), or a contact picker when none is set. */
    public function whatsappUrl(array $recap): string
    {
        $number = preg_replace('/\D/', '', (string) Setting::get('ck.whatsapp', ''));

        return 'https://wa.me/'.($number ?: '').'?text='.rawurlencode($this->whatsappText($recap));
    }

    /** Quantities per product and variant, with a breakdown per outlet; largest first. */
    private function totals(Collection $lines, array $outlets): array
    {
        return $lines->groupBy(fn ($line) => mb_strtolower($line['name'].'|'.$line['variant']))
            ->map(fn (Collection $group) => [
                'name' => $group->first()['name'], 'variant' => $group->first()['variant'], 'category' => $group->first()['category'],
                'quantity' => (int) $group->sum('quantity'),
                'by_outlet' => collect($outlets)->mapWithKeys(fn ($outlet) => [$outlet => (int) $group->where('outlet', $outlet)->sum('quantity')])->all(),
            ])
            ->sortBy([['category', 'asc'], ['name', 'asc'], ['variant', 'asc']])->values()->all();
    }
}

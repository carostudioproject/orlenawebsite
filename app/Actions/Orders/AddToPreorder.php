<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderAddition;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Services\Ordering\OrderPricing;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds customer-chosen items to their own order before any payment exists. Prices come from the database
 * for the order's outlet; the same product at the same price is merged into its existing line. Hampers and boxes with
 * chosen contents always get a new line.
 */
class AddToPreorder
{
    public function __construct(private OrderPricing $pricing) {}

    public function handle(Order $order, array $data): OrderAddition
    {
        // A retried request (double click, back/forward) returns the addition already stored.
        if ($existing = OrderAddition::where('add_key', $data['add_key'])->first()) {
            abort_unless($existing->order_id === $order->id, 404);

            return $existing;
        }

        return DB::transaction(function () use ($order, $data) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            if ($reason = $locked->additionBlocker()) {
                throw ValidationException::withMessages(['order' => $reason]);
            }
            $outlet = Outlet::lockForUpdate()->find($locked->outlet_id);
            if (! $outlet?->takesPreorders()) {
                throw ValidationException::withMessages(['order' => 'Outlet pesanan ini sedang tidak menerima PO. Hubungi admin Orlena melalui WhatsApp.']);
            }
            $quote = $this->pricing->quote($data['items'], $outlet);

            $lines = $locked->items()->whereNull('parent_item_id')->lockForUpdate()->get();
            if ($lines->count() + collect($quote['items'])->filter(fn ($item) => ! $lines->contains(fn ($line) => $this->sameLine($line, $item)))->count() > 50) {
                throw ValidationException::withMessages(['items' => 'Satu pesanan maksimal berisi 50 produk berbeda.']);
            }
            foreach ($quote['items'] as $index => $item) {
                $line = $lines->first(fn ($line) => $this->sameLine($line, $item));
                if ($line && $line->quantity + $item['quantity'] > 99) {
                    throw ValidationException::withMessages(["items.$index.quantity" => 'Jumlah '.$item['name'].' dalam pesanan maksimal 99.']);
                }
                if ($line) {
                    $line->update(['quantity' => $line->quantity + $item['quantity'], 'subtotal' => $line->subtotal + $item['subtotal']]);
                } else {
                    $locked->addLine($item);
                }
            }

            $previousTotal = $locked->total;
            $subtotal = $locked->subtotal + $quote['subtotal'];
            // A new review version makes any review form opened before this change reload first.
            $locked->update(['subtotal' => $subtotal, 'total' => $subtotal + ($locked->delivery_fee ?? 0), 'review_version' => $locked->review_version + 1]);
            $addition = $locked->additions()->create([
                'add_key' => $data['add_key'], 'subtotal_added' => $quote['subtotal'], 'previous_total' => $previousTotal, 'new_total' => $locked->total,
                'items' => collect($quote['items'])->map(fn ($item) => [
                    'name' => $item['name'], 'variant' => $item['variant'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'],
                    'subtotal' => $item['subtotal'] + array_sum(array_column($item['contents'], 'subtotal')),
                    'contents' => array_map(fn ($content) => $content['quantity'].'x '.$content['name'].($content['variant'] ? ' ('.$content['variant'].')' : ''), $item['contents']),
                    'input' => OrderItem::formatInput($item['input_label'], $item['input_texts']),
                ])->all(),
            ]);
            Audit::record('order.items_added', $locked, ['addition_id' => $addition->id, 'subtotal_added' => $quote['subtotal'], 'total' => $locked->total], null);

            return $addition;
        }, 3);
    }

    private function sameLine($line, array $item): bool
    {
        return ! $item['contents'] && $line->product_id === $item['product_id'] && $line->unit_price_snapshot === $item['unit_price'] && ! $line->input_texts && ! $item['input_texts']
            && ! $line->contents()->exists();
    }
}

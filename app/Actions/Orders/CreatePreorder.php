<?php

namespace App\Actions\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Services\Ordering\OrderPricing;
use App\Support\Audit;
use App\Support\PreorderDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePreorder
{
    public function handle(Request $request, array $data, OrderPricing $pricing): Order
    {
        $owner = $request->session()->get('order_owner');
        abort_unless(is_string($owner), 419);
        $ownerHash = hash('sha256', $owner);
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        // A retried request returns the committed order after the form token has been rotated.
        if ($existing = Order::where('checkout_key', $data['checkout_key'])->first()) {
            abort_unless(hash_equals($existing->owner_hash, $ownerHash), 404);
            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw ValidationException::withMessages(['checkout' => 'Permintaan ini sudah tersimpan. Buka ringkasan pesanan atau mulai pesanan baru.']);
            }

            return $existing;
        }
        if ($request->session()->get('checkout_key') !== $data['checkout_key']) {
            throw ValidationException::withMessages(['checkout' => 'Sesi form berubah. Muat ulang halaman untuk melanjutkan.']);
        }

        return DB::transaction(function () use ($data, $pricing, $ownerHash, $requestHash) {
            // Pickup uses the chosen outlet; delivery (Gojek/Grab) always ships from the delivery outlet.
            if ($data['fulfillment_method'] === 'delivery') {
                $outlet = Outlet::where('is_delivery_hub', true)->lockForUpdate()->first();
                if (! $outlet?->takesPreorders()) {
                    throw ValidationException::withMessages(['fulfillment_method' => 'Delivery sedang tidak tersedia. Pilih pickup di outlet.']);
                }
            } else {
                $outlet = Outlet::lockForUpdate()->find($data['outlet_id']);
                if (! $outlet?->takesPreorders()) {
                    throw ValidationException::withMessages(['outlet_id' => 'Outlet ini belum menerima PO. Pilih outlet lain.']);
                }
            }
            $quote = $pricing->quote($data['items'], $outlet);
            $dates = app(PreorderDate::class);
            $earliest = $dates->earliest();
            if ($data['requested_date'] < $earliest) {
                throw ValidationException::withMessages(['requested_date' => 'Tanggal ini sudah ditutup. Pilih tanggal '.$earliest.' atau setelahnya (batas pemesanan '.$dates->cutoffLabel().').']);
            }
            if ($closed = $dates->closedReason($data['requested_date'])) {
                throw ValidationException::withMessages(['requested_date' => $closed.' Pilih tanggal lain.']);
            }
            // Do not merge customers based on an unverified phone/email supplied by another visitor.
            $customer = Customer::create(['name' => $data['name'], 'whatsapp' => $data['whatsapp'], 'email' => $data['email'] ?? null]);
            $order = Order::create([
                'order_code' => 'ORL-'.now('Asia/Makassar')->format('ymd').'-'.strtoupper(Str::random(10)),
                'checkout_key' => $data['checkout_key'], 'owner_hash' => $ownerHash, 'request_hash' => $requestHash,
                'customer_id' => $customer->id, 'outlet_id' => $outlet->id, 'outlet_name_snapshot' => $outlet->name,
                'fulfillment_method' => $data['fulfillment_method'], 'requested_date' => $data['requested_date'],
                'requested_time' => $data['requested_time'] ?? null,
                'delivery_address' => $data['fulfillment_method'] === 'delivery' ? $data['delivery_address'] : null,
                'customer_note' => $data['customer_note'] ?? null,
                'subtotal' => $quote['subtotal'], 'delivery_fee' => $data['fulfillment_method'] === 'pickup' ? 0 : null,
                'total' => $quote['subtotal'], 'order_status' => 'pending_review', 'payment_status' => 'not_created',
            ]);
            foreach ($quote['items'] as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'], 'product_name_snapshot' => $item['name'], 'category_snapshot' => $item['category'], 'variant_snapshot' => $item['variant'],
                    'sku_snapshot' => $item['sku'], 'unit_price_snapshot' => $item['unit_price'], 'quantity' => $item['quantity'], 'subtotal' => $item['subtotal'],
                ]);
            }
            DB::table('order_status_histories')->insert(['order_id' => $order->id, 'actor_id' => null, 'from_status' => null, 'to_status' => 'pending_review', 'created_at' => now()]);
            Audit::record('order.created', $order, ['order_code' => $order->order_code, 'order_status' => 'pending_review']);

            return $order;
        }, 3);
    }
}

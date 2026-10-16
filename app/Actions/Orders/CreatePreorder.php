<?php

namespace App\Actions\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Services\Ordering\Capacity;
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
            // Pickup uses the chosen outlet; delivery (Gojek/Grab) ships from the chosen delivery outlet, or from the first one
            // when the customer asks Orlena for the nearest (staff change it during review). The lock keeps quotas exact.
            if ($data['fulfillment_method'] === 'delivery') {
                $outlet = $data['nearest_outlet'] ? Outlet::delivering()->lockForUpdate()->first()
                    : Outlet::delivering()->whereKey($data['outlet_id'])->lockForUpdate()->first();
                if (! $outlet) {
                    throw ValidationException::withMessages([$data['nearest_outlet'] ? 'fulfillment_method' : 'outlet_id' => 'Outlet ini sedang tidak melayani delivery. Pilih outlet lain atau pickup.']);
                }
            } else {
                $outlet = Outlet::lockForUpdate()->find($data['outlet_id']);
                if (! $outlet?->takesPreorders()) {
                    throw ValidationException::withMessages(['outlet_id' => 'Outlet ini belum menerima PO. Pilih outlet lain.']);
                }
            }
            $quote = $pricing->quote($data['items'], $outlet);
            $minimum = app(PreorderDate::class)->deliveryMinOrder();
            if ($data['fulfillment_method'] === 'delivery' && $minimum && $quote['subtotal'] < $minimum) {
                throw ValidationException::withMessages(['fulfillment_method' => 'Minimum belanja untuk delivery Rp '.number_format($minimum, 0, ',', '.').'. Tambah produk atau pilih pickup.']);
            }
            $dates = app(PreorderDate::class);
            $earliest = $dates->earliest();
            if ($data['requested_date'] < $earliest) {
                throw ValidationException::withMessages(['requested_date' => 'Tanggal ini sudah ditutup. Pilih tanggal '.$earliest.' atau setelahnya (batas pemesanan '.$dates->cutoffLabel().').']);
            }
            if ($data['requested_date'] > ($latest = $dates->latest())) {
                throw ValidationException::withMessages(['requested_date' => 'PO paling lama '.$dates->maxDays().' hari ke depan. Pilih tanggal sampai '.$latest.'.']);
            }
            if ($closed = $dates->closedReason($data['requested_date'])) {
                throw ValidationException::withMessages(['requested_date' => $closed.' Pilih tanggal lain.']);
            }
            $capacity = app(Capacity::class);
            if (! in_array($data['requested_time'], $capacity->slots($outlet), true)) {
                throw ValidationException::withMessages(['requested_time' => 'Pilih jam dari pilihan slot yang tersedia.']);
            }
            if ($full = $capacity->problem($outlet, $data['requested_date'], $data['requested_time'], $data['fulfillment_method'])) {
                throw ValidationException::withMessages([str_contains($full, 'Slot jam') ? 'requested_time' : 'requested_date' => $full]);
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
                'recipient_whatsapp' => $data['fulfillment_method'] === 'delivery' ? $data['recipient_whatsapp'] : null,
                'location_url' => $data['fulfillment_method'] === 'delivery' ? $data['location_url'] : null,
                'nearest_outlet' => $data['nearest_outlet'],
                'customer_note' => $data['customer_note'] ?? null,
                // The greeting card goes with hampers, or with a card bought as an extra.
                'card_message' => filled($data['card_message'] ?? null) && Product::whereIn('id', array_column($quote['items'], 'product_id'))->get(['id', 'name', 'is_hamper', 'content_type'])
                    ->contains(fn (Product $product) => $product->is_hamper || $product->content_type === 'hampers' || $product->isGreetingCard())
                    ? trim($data['card_message']) : null,
                'subtotal' => $quote['subtotal'], 'delivery_fee' => $data['fulfillment_method'] === 'pickup' ? 0 : null,
                'total' => $quote['subtotal'], 'order_status' => 'pending_review', 'payment_status' => 'not_created',
            ]);
            foreach ($quote['items'] as $item) {
                $order->addLine($item);
            }
            DB::table('order_status_histories')->insert(['order_id' => $order->id, 'actor_id' => null, 'from_status' => null, 'to_status' => 'pending_review', 'created_at' => now()]);
            Audit::record('order.created', $order, ['order_code' => $order->order_code, 'order_status' => 'pending_review']);

            return $order;
        }, 3);
    }
}

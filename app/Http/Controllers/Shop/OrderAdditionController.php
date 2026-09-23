<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Orders\AddToPreorder;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\OrderAccess;
use App\Support\OrderCatalog;
use App\Support\ShopPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Customers add items to their own unpaid order after proving it is theirs with Order Code + WhatsApp number. */
class OrderAdditionController extends Controller
{
    public function find(Request $request)
    {
        return ShopPage::render($request, 'FindOrder', 'Tambah pesanan', ['code' => $request->string('code')->limit(40, '')->upper()->value()]);
    }

    public function verify(Request $request)
    {
        $phone = preg_replace('/[\s()+-]/', '', (string) $request->input('whatsapp'));
        $request->merge(['order_code' => strtoupper(trim((string) $request->input('order_code'))), 'whatsapp' => str_starts_with($phone, '0') ? '62'.substr($phone, 1) : $phone]);
        $data = $request->validate(
            ['order_code' => ['required', 'string', 'max:40'], 'whatsapp' => ['required', 'regex:/^[1-9][0-9]{7,14}$/']],
            ['order_code.required' => 'Masukkan Order Code.', 'whatsapp.required' => 'Masukkan nomor WhatsApp yang dipakai saat memesan.', 'whatsapp.regex' => 'Masukkan nomor WhatsApp yang valid, misalnya 081234567890.'],
        );
        // Guessing is slowed per device and per order code; the message never reveals which half was wrong.
        $keys = ['order-lookup:'.$request->ip(), 'order-lookup:'.$data['order_code']];
        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, 5)) {
                throw ValidationException::withMessages(['order_code' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.']);
            }
        }
        $order = Order::with('customer')->where('order_code', $data['order_code'])->first();
        if (! $order || ! hash_equals($order->customer->whatsapp, $data['whatsapp'])) {
            foreach ($keys as $key) {
                RateLimiter::hit($key, 300);
            }
            throw ValidationException::withMessages(['order_code' => 'Order Code atau nomor WhatsApp tidak cocok.']);
        }
        array_map(fn ($key) => RateLimiter::clear($key), $keys);
        OrderAccess::grant($request, $order);

        return redirect('/orders/'.$order->order_code.'/tambah');
    }

    public function create(Request $request, string $code)
    {
        $order = Order::with('items', 'outlet')->where('order_code', $code)->first();
        if (! $order || ! OrderAccess::allows($request, $order)) {
            return redirect('/order/tambah?code='.rawurlencode($code))->with('notice', 'Masukkan Order Code dan nomor WhatsApp untuk menambah pesanan.');
        }
        if (! $request->session()->has('add_key')) {
            $request->session()->put('add_key', (string) Str::uuid());
        }

        return ShopPage::render($request, 'AddItems', 'Tambah pesanan', [
            ...OrderCatalog::for($order->outlet),
            // only() skips the model's date format, so the PO date is formatted here.
            'order' => $order->only('order_code', 'fulfillment_method', 'outlet_name_snapshot', 'requested_time', 'subtotal', 'delivery_fee', 'total')
                + ['requested_date' => $order->requested_date->toDateString(), 'items' => $order->items],
            'blocker' => $order->additionBlocker(), 'addKey' => $request->session()->get('add_key'),
        ]);
    }

    public function store(Request $request, string $code, AddToPreorder $add)
    {
        $order = Order::where('order_code', $code)->firstOrFail();
        abort_unless(OrderAccess::allows($request, $order), 404);
        $data = $request->validate([
            'add_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['array:product_id,quantity,quoted_price'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.quoted_price' => ['required', 'integer', 'min:1', 'max:999999999'],
        ], ['items.required' => 'Pilih minimal satu produk untuk ditambahkan.', 'items.min' => 'Pilih minimal satu produk untuk ditambahkan.']);
        $add->handle($order, $data);
        // A fresh key for the next addition; a late retry of this one still resolves to the stored addition.
        if ($request->session()->get('add_key') === $data['add_key']) {
            $request->session()->forget('add_key');
        }

        return redirect('/orders/'.$order->order_code)->with('notice', 'Tambahan pesanan tersimpan. Kirim update ke Orlena melalui WhatsApp agar segera dicek.');
    }
}

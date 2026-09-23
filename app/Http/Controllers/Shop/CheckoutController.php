<?php

namespace App\Http\Controllers\Shop;

use App\Actions\Orders\CreatePreorder;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\Order;
use App\Models\Outlet;
use App\Services\Ordering\OrderPricing;
use App\Support\OrderAccess;
use App\Support\OrderCatalog;
use App\Support\OrderWhatsApp;
use App\Support\PreorderDate;
use App\Support\ShopPage;
use App\Support\SiteContent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function index(Request $request, PreorderDate $date)
    {
        $request->validate(['outlet_id' => ['nullable', 'integer', 'min:1']]);
        $outlets = Outlet::takingPreorders()->orderBy('position')->orderBy('name')->get(['id', 'name', 'address', 'image']);
        $hub = Outlet::deliveryHub();
        // Prices follow the outlet that fulfils the order: the chosen pickup outlet, or the delivery outlet.
        $pricing = $outlets->firstWhere('id', $request->integer('outlet_id')) ?? ($hub?->id === $request->integer('outlet_id') ? $hub : null);
        if (! $request->session()->has('order_owner')) {
            $request->session()->put('order_owner', Str::random(64));
        }
        if (! $request->session()->has('checkout_key')) {
            $request->session()->put('checkout_key', (string) Str::uuid());
        }

        return ShopPage::render($request, 'OrderForm', 'Form pre-order', [
            ...OrderCatalog::for($pricing),
            'outlets' => $outlets, 'deliveryOutlet' => $hub?->only('id', 'name', 'address'), 'selectedOutlet' => $pricing?->id,
            'earliestDate' => $date->earliest(), 'closedDates' => $date->upcomingClosedDates(), 'closedWeekdays' => $date->closedWeekdays(), 'checkoutKey' => $request->session()->get('checkout_key'),
        ]);
    }

    public function store(CheckoutRequest $request, OrderPricing $pricing, CreatePreorder $create)
    {
        $order = $create->handle($request, $request->validated(), $pricing);
        // A late retry must not clear a newer form's token.
        if ($request->session()->get('checkout_key') === $order->checkout_key) {
            $request->session()->forget('checkout_key');
        }

        return redirect('/orders/'.$order->order_code);
    }

    public function show(Request $request, string $code, SiteContent $content)
    {
        $order = Order::with('customer', 'items')->where('order_code', $code)->firstOrFail();
        abort_unless(OrderAccess::allows($request, $order), 404);
        $number = $content->get('social')['whatsappNumber'] ?? '';
        $link = fn (string $text) => preg_match('/^[1-9][0-9]{7,14}$/', $number) ? 'https://wa.me/'.$number.'?text='.rawurlencode($text) : null;
        // After the customer adds items, offer a WhatsApp update that lists only what changed.
        $latest = $order->additions()->latest('id')->first();

        return ShopPage::render($request, 'Confirmation', 'Pesanan tersimpan', [
            'order' => $order, 'whatsappUrl' => $link(OrderWhatsApp::message($order)),
            'canAddItems' => $order->additionBlocker() === null,
            'latestAddition' => $latest ? [...$latest->only('items', 'subtotal_added', 'new_total', 'created_at'), 'whatsappUrl' => $link(OrderWhatsApp::additionMessage($order, $latest))] : null,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Customers are stored per order (identities are never merged from unverified input), so this view groups
 * orders by WhatsApp number for staff. Names and emails are shown as entered on each order.
 */
class CustomerController extends Controller
{
    private const PAID_TOTAL = "SUM(CASE WHEN o.payment_status = 'paid' AND o.order_status != 'cancelled' THEN o.total ELSE 0 END)";

    public function index(Request $request)
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'sort' => ['nullable', 'in:recent,spent,orders'], 'page' => ['nullable', 'integer', 'min:1']]);
        $customers = DB::table('customers as c')->join('orders as o', 'o.customer_id', '=', 'c.id')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('c.name', 'like', '%'.$search.'%')->orWhere('c.whatsapp', 'like', '%'.$search.'%')))
            ->selectRaw('c.whatsapp, COUNT(o.id) as orders, '.self::PAID_TOTAL.' as spent, MAX(o.created_at) as last_order_at, MAX(c.id) as latest_id')
            ->groupBy('c.whatsapp')
            ->when(($filters['sort'] ?? 'recent') === 'spent', fn ($q) => $q->orderByDesc('spent'))
            ->when(($filters['sort'] ?? 'recent') === 'orders', fn ($q) => $q->orderByDesc('orders'))
            ->orderByDesc('last_order_at')->paginate(10)->withQueryString();
        $names = Customer::whereIn('id', $customers->getCollection()->pluck('latest_id'))->pluck('name', 'id');
        $customers->getCollection()->transform(fn ($row) => [
            'whatsapp' => $row->whatsapp, 'name' => $names[$row->latest_id] ?? '—', 'orders' => (int) $row->orders,
            'spent' => (int) $row->spent, 'last_order_at' => $row->last_order_at,
        ]);

        // An object, never []: filters.sort would otherwise resolve to Array.prototype.sort in the browser.
        return Inertia::render('Admin/Customers/Index', ['customers' => $customers, 'filters' => (object) $filters]);
    }

    public function show(string $whatsapp)
    {
        abort_unless(preg_match('/^[0-9]{8,20}$/', $whatsapp), 404);
        $ids = Customer::where('whatsapp', $whatsapp)->pluck('id');
        abort_if($ids->isEmpty(), 404);
        Inertia::encryptHistory();
        $orders = Order::whereIn('customer_id', $ids)->with('customer:id,name')->latest('id')->paginate(10, ['id', 'order_code', 'customer_id', 'outlet_name_snapshot', 'fulfillment_method', 'requested_date', 'total', 'order_status', 'payment_status', 'created_at']);
        $totals = DB::table('orders as o')->whereIn('o.customer_id', $ids)->selectRaw('COUNT(*) as orders, '.self::PAID_TOTAL.' as spent, MIN(o.created_at) as first_order_at, MAX(o.created_at) as last_order_at')->first();
        // Favourite products across this number's paid orders.
        $favourites = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')->whereIn('o.customer_id', $ids)
            ->where('o.payment_status', 'paid')->where('o.order_status', '!=', 'cancelled')
            ->selectRaw('i.product_name_snapshot as name, i.variant_snapshot as variant, SUM(i.quantity) as quantity')
            ->groupBy('i.product_name_snapshot', 'i.variant_snapshot')->orderByDesc('quantity')->limit(5)->get();

        return Inertia::render('Admin/Customers/Show', [
            'whatsapp' => $whatsapp,
            'names' => Customer::whereIn('id', $ids)->latest('id')->pluck('name')->unique()->values(),
            'emails' => Customer::whereIn('id', $ids)->whereNotNull('email')->latest('id')->pluck('email')->unique()->values(),
            'totals' => ['orders' => (int) $totals->orders, 'spent' => (int) $totals->spent, 'first_order_at' => $totals->first_order_at, 'last_order_at' => $totals->last_order_at],
            'favourites' => $favourites, 'orders' => $orders,
        ]);
    }
}

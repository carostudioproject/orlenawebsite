<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportsAndOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Product $berry;

    private Product $sauce;

    private Outlet $north;

    private Outlet $south;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2030-01-20 12:00:00', 'Asia/Makassar'));
        $brownies = Category::create(['name' => 'Brownies']);
        $this->berry = Product::create(['category_id' => $brownies->id, 'variant' => 'Fullsize', 'sku' => 'B-F', 'name' => 'Berry', 'price' => 100000, 'is_active' => true]);
        $this->sauce = Product::create(['category_id' => $brownies->id, 'sku' => 'S-1', 'name' => 'Nutella', 'price' => 20000, 'is_active' => true]);
        $this->north = Outlet::create(['code' => 'N', 'name' => 'North', 'address' => 'Jl', 'is_active' => true]);
        $this->south = Outlet::create(['code' => 'S', 'name' => 'South', 'address' => 'Jl', 'is_active' => true]);
    }

    /** An order with lines [[product, qty], ...]; paid at $paidAt when given. */
    private function order(Outlet $outlet, array $lines, ?string $paidAt, array $overrides = [], string $whatsapp = '6281111111111', string $name = 'Sinta'): Order
    {
        $subtotal = array_sum(array_map(fn ($line) => $line[0]->price * $line[1], $lines));
        $fee = $overrides['delivery_fee'] ?? 0;
        $customer = Customer::create(['name' => $name, 'whatsapp' => $whatsapp]);
        $order = Order::create([
            'order_code' => 'ORL-300101-'.strtoupper(Str::random(10)), 'checkout_key' => (string) Str::uuid(), 'owner_hash' => str_repeat('a', 64), 'request_hash' => str_repeat('b', 64),
            'customer_id' => $customer->id, 'outlet_id' => $outlet->id, 'outlet_name_snapshot' => $outlet->name, 'fulfillment_method' => 'pickup',
            'requested_date' => '2030-01-25', 'subtotal' => $subtotal, 'delivery_fee' => $fee, 'total' => $subtotal + $fee,
            'order_status' => $paidAt ? 'processing' : 'pending_review', 'payment_status' => $paidAt ? 'paid' : 'not_created', ...$overrides,
        ]);
        foreach ($lines as [$product, $quantity]) {
            $order->items()->create([
                'product_id' => $product->id, 'product_name_snapshot' => $product->name, 'category_snapshot' => 'Brownies', 'variant_snapshot' => $product->variant,
                'sku_snapshot' => $product->sku, 'unit_price_snapshot' => $product->price, 'quantity' => $quantity, 'subtotal' => $product->price * $quantity,
            ]);
        }
        if ($paidAt) {
            Payment::create([
                'order_id' => $order->id, 'attempt' => 1, 'provider_order_id' => $order->order_code.'-P1', 'amount' => $order->total,
                'status' => $order->payment_status === 'refunded' ? 'refunded' : 'paid', 'payment_type' => 'qris', 'paid_at' => $paidAt,
            ]);
        }

        return $order;
    }

    private function seedSales(): void
    {
        $this->order($this->north, [[$this->berry, 2], [$this->sauce, 1]], '2030-01-05 10:00:00', ['delivery_fee' => 15000]);
        $this->order($this->south, [[$this->berry, 1]], '2030-01-06 23:30:00', ['order_status' => 'completed'], '6282222222222', 'Budi');
        $this->order($this->north, [[$this->sauce, 3]], '2030-01-06 09:00:00', ['order_status' => 'cancelled'], '6282222222222', 'Budi W');
        $this->order($this->north, [[$this->berry, 5]], '2030-01-07 09:00:00', ['payment_status' => 'refunded']);
        // Outside the period and unpaid orders never count as revenue.
        $this->order($this->north, [[$this->berry, 9]], '2029-12-31 23:59:59');
        $this->order($this->south, [[$this->berry, 1]], null);
    }

    public function test_sales_report_counts_paid_orders_by_payment_time_and_filters(): void
    {
        $this->seedSales();
        $this->actingAs(User::factory()->create())->get('/admin/reports')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'finance']));

        $this->get('/admin/reports?from=2030-01-01&to=2030-01-31')->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/Index')
            // North: 200.000 + 20.000 + 15.000 ongkir; South: 100.000.
            ->where('report.summary.revenue', 335000)->where('report.summary.transactions', 2)->where('report.summary.average', 167500)
            ->where('report.summary.product_sales', 320000)->where('report.summary.delivery_fees', 15000)->where('report.summary.items_sold', 4)
            ->where('report.summary.voided_orders', 2)->where('report.summary.voided_amount', 560000)
            ->where('report.outlets.0', ['outlet_id' => $this->north->id, 'outlet' => 'North', 'transactions' => 1, 'revenue' => 235000])
            ->where('report.products.0', ['product_id' => $this->berry->id, 'name' => 'Berry', 'variant' => 'Fullsize', 'category' => 'Brownies', 'quantity' => 3, 'revenue' => 300000, 'orders' => 2])
            ->where('report.daily.5', ['date' => '2030-01-06', 'transactions' => 1, 'revenue' => 100000])
            ->where('report.order_statuses.processing', 3)->where('report.placed_orders', 6));

        $this->get('/admin/reports?from=2030-01-01&to=2030-01-31&outlet_id='.$this->south->id)->assertInertia(fn (Assert $page) => $page->where('report.summary.revenue', 100000)->where('report.labels.outlet', 'South'));
        $this->get('/admin/reports?from=2030-01-01&to=2030-01-31&product_id='.$this->sauce->id)->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.revenue', 20000)->where('report.summary.transactions', 1)->where('report.summary.delivery_fees', null)->has('report.products', 1));
        $this->get('/admin/reports?from=2030-01-01&to=2030-01-31&status=completed')->assertInertia(fn (Assert $page) => $page->where('report.summary.transactions', 1));
        $this->get('/admin/reports?from=2030-01-31&to=2030-01-01')->assertSessionHasErrors('to');
        // Default period is the current month.
        $this->get('/admin/reports')->assertInertia(fn (Assert $page) => $page->where('report.filters.from', '2030-01-01')->where('report.filters.to', '2030-01-20'));

        $this->get('/admin/reports/print?from=2030-01-01&to=2030-01-31')->assertOk()->assertSee('Sales &amp; Performance Report', false)->assertSee('Rp 335.000')->assertSee('Berry (Fullsize)');
        $pdf = $this->get('/admin/reports/pdf?from=2030-01-01&to=2030-01-31')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $excel = $this->get('/admin/reports/excel?from=2030-01-01&to=2030-01-31')->assertOk()->assertDownload('orlena-sales-report-2030-01-01-to-2030-01-31.xlsx');
        $this->assertStringStartsWith('PK', file_get_contents($excel->baseResponse->getFile()->getPathname()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported']);
    }

    public function test_customers_are_grouped_by_whatsapp_and_payments_are_listed(): void
    {
        $this->seedSales();
        $this->actingAs(User::factory()->create(['role' => 'content_editor']))->get('/admin/customers')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'finance']));

        $this->get('/admin/customers?sort=spent')->assertInertia(fn (Assert $page) => $page->component('Admin/Customers/Index')->has('customers.data', 2)
            // Sinta: 235.000 + 900.000 (paid, earlier period); refunded and unpaid orders excluded.
            ->where('customers.data.0', fn ($row) => $row['whatsapp'] === '6281111111111' && $row['spent'] === 1135000 && $row['orders'] === 4)
            ->where('customers.data.1.name', 'Budi W'));
        $this->get('/admin/customers?search=budi')->assertInertia(fn (Assert $page) => $page->has('customers.data', 1));
        $this->get('/admin/customers/6282222222222')->assertInertia(fn (Assert $page) => $page->component('Admin/Customers/Show')
            ->where('names', ['Budi W', 'Budi'])->where('totals.orders', 2)->where('totals.spent', 100000)->has('orders.data', 2)->where('favourites.0.name', 'Berry'));
        $this->get('/admin/customers/6280000000000')->assertNotFound();

        $this->get('/admin/payments?status=refunded')->assertInertia(fn (Assert $page) => $page->component('Admin/Payments/Index')->has('payments.data', 1)
            ->missing('payments.data.0.snap_token')->where('payments.data.0.order.customer.name', 'Sinta'));
        $this->get('/admin/payments?search=Budi')->assertInertia(fn (Assert $page) => $page->has('payments.data', 2));
    }
}

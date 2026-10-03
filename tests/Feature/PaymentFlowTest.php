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
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'SK-doku-secret-TEST';

    private const CLIENT = 'BRN-0001-TEST';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.doku.enabled' => true, 'services.doku.mode' => 'checkout', 'services.doku.client_id' => self::CLIENT, 'services.doku.secret_key' => self::KEY, 'services.doku.is_production' => false]);
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'Asia/Makassar'));
    }

    private function order(array $overrides = []): Order
    {
        $category = Category::firstOrCreate(['name' => 'FULLSIZE TEST']);
        $product = Product::create(['category_id' => $category->id, 'sku' => 'SKU-'.Str::random(6), 'name' => 'Test Brownie', 'price' => 80000, 'is_active' => true]);
        $outlet = Outlet::firstOrCreate(['code' => 'TEST'], ['name' => 'Test Outlet', 'address' => 'Address', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Customer Test', 'whatsapp' => '6281234567890', 'email' => null]);
        $order = Order::create([
            'order_code' => 'ORL-300101-'.strtoupper(Str::random(10)), 'checkout_key' => (string) Str::uuid(), 'owner_hash' => str_repeat('a', 64),
            'request_hash' => str_repeat('b', 64), 'customer_id' => $customer->id, 'outlet_id' => $outlet->id, 'outlet_name_snapshot' => $outlet->name,
            'fulfillment_method' => 'delivery', 'requested_date' => '2030-01-03', 'delivery_address' => 'Destination',
            'subtotal' => 160000, 'delivery_fee' => 15000, 'total' => 175000, 'order_status' => 'pending_review', 'payment_status' => 'not_created', ...$overrides,
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'product_name_snapshot' => 'Test Brownie', 'category_snapshot' => 'FULLSIZE TEST', 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 80000, 'quantity' => 2, 'subtotal' => 160000,
        ]);
        DB::table('order_status_histories')->insert(['order_id' => $order->id, 'from_status' => null, 'to_status' => $order->order_status, 'created_at' => now()]);

        return $order;
    }

    /** Queued responses are used first; afterwards Checkout succeeds and Check Status reports no transaction. */
    private function fakeDoku(array $checkout = [], array $status = []): void
    {
        $checkoutSequence = Http::sequence($checkout)->whenEmpty(Http::response(['message' => ['SUCCESS'], 'response' => [
            'order' => ['session_id' => 'session-1'], 'payment' => ['token_id' => 'doku-token', 'url' => 'https://sandbox.doku.com/checkout-link-v2/doku-token'],
        ]]));
        $statusSequence = Http::sequence($status)->whenEmpty(Http::response(['error' => ['message' => 'Order not found']], 404));
        Http::fake([
            '*/checkout/v3/cancellations' => Http::response(['message' => ['SUCCESS']]),
            '*/checkout/v1/payment' => $checkoutSequence,
            '*/orders/v1/status/*' => $statusSequence,
        ]);
    }

    /** A DOKU Checkout HTTP Notification for this payment. */
    private function notify(Payment $payment, string $status, array $extra = [], ?string $signature = null)
    {
        $body = array_replace_recursive([
            'service' => ['id' => 'QRIS'], 'acquirer' => ['id' => 'NOBU'], 'channel' => ['id' => 'QRIS'],
            'order' => ['invoice_number' => $payment->provider_order_id, 'amount' => $payment->amount],
            'transaction' => ['status' => $status, 'date' => '2030-01-01T04:00:00Z', 'original_request_id' => 'x'],
        ], $extra);

        return $this->signedNotification(json_encode($body, JSON_UNESCAPED_SLASHES), $signature);
    }

    /** Signed the way DOKU signs notifications: HMAC-SHA256 over the headers, our path and the body digest. */
    private function signedNotification(string $body, ?string $signature = null)
    {
        $requestId = (string) Str::uuid();
        $timestamp = '2030-01-01T04:00:01Z';
        $components = 'Client-Id:'.self::CLIENT."\nRequest-Id:".$requestId."\nRequest-Timestamp:".$timestamp
            ."\nRequest-Target:/webhooks/doku\nDigest:".base64_encode(hash('sha256', $body, true));
        $signature ??= 'HMACSHA256='.base64_encode(hash_hmac('sha256', $components, self::KEY, true));

        return $this->call('POST', '/webhooks/doku', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_CLIENT_ID' => self::CLIENT,
            'HTTP_REQUEST_ID' => $requestId, 'HTTP_REQUEST_TIMESTAMP' => $timestamp, 'HTTP_SIGNATURE' => $signature,
        ], $body);
    }

    private function confirm(Order $order): void
    {
        $this->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => $order->fresh()->review_version])->assertSessionHasNoErrors();
    }

    public function test_confirmation_creates_one_signed_doku_link_capped_by_twenty_four_hours(): void
    {
        $this->fakeDoku();
        $order = $this->order();
        $staff = User::factory()->create();
        $this->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertRedirect('/admin/login');
        $this->actingAs($staff);
        $this->confirm($order);

        $order->refresh();
        $this->assertSame(['confirmed', 'pending'], [$order->order_status, $order->payment_status]);
        $payment = Payment::sole();
        $this->assertSame([$order->order_code.'-P1', 'pending', 175000, 'doku'], [$payment->provider_order_id, $payment->status, $payment->amount, $payment->provider]);
        $this->assertSame(['https://sandbox.doku.com/checkout-link-v2/doku-token', 'session-1'], [$payment->payment_url, $payment->transaction_id]);
        $this->assertSame('2030-01-02 12:00:00', $payment->expires_at->format('Y-m-d H:i:s'));
        Http::assertSent(function (HttpRequest $request) use ($order, $payment) {
            $items = collect($request['order']['line_items']);
            $components = 'Client-Id:'.self::CLIENT."\nRequest-Id:".$payment->provider_request_id."\nRequest-Timestamp:2030-01-01T04:00:00Z"
                ."\nRequest-Target:/checkout/v1/payment\nDigest:".base64_encode(hash('sha256', $request->body(), true));

            return $request->url() === 'https://api-sandbox.doku.com/checkout/v1/payment'
                && $request->header('Client-Id') === [self::CLIENT] && $request->header('Request-Id') === [$payment->provider_request_id]
                && $request->header('Request-Timestamp') === ['2030-01-01T04:00:00Z']
                && $request->header('Signature') === ['HMACSHA256='.base64_encode(hash_hmac('sha256', $components, self::KEY, true))]
                && $request['order']['invoice_number'] === $order->order_code.'-P1' && $request['order']['amount'] === 175000
                && $request['payment'] === ['payment_due_date' => 1440]
                && $request['customer']['phone'] === '6281234567890'
                && $items->sum(fn ($item) => $item['price'] * $item['quantity']) === 175000;
        });

        $this->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertSessionHasErrors('payment');
        $this->post('/admin/orders/'.$order->id.'/payments/retry')->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 1);
        $this->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->has('payments', 1)
            ->missing('payments.0.checkout_token')->missing('payments.0.provider_request_id')->where('payments.0.payment_url', $payment->payment_url))->assertDontSee(self::KEY);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'from_status' => 'pending_review', 'to_status' => 'confirmed', 'actor_id' => $staff->id]);
    }

    public function test_link_expiry_stops_at_cutoff_and_closed_dates_must_be_rescheduled(): void
    {
        $this->fakeDoku();
        $this->actingAs(User::factory()->create());
        $order = $this->order(['requested_date' => '2030-01-02']);
        $this->confirm($order);
        $this->assertSame('2030-01-01 18:00:00', Payment::sole()->expires_at->format('Y-m-d H:i:s'));
        Http::assertSent(fn (HttpRequest $request) => $request['payment'] === ['payment_due_date' => 360]);

        $this->travelTo(CarbonImmutable::parse('2030-01-01 17:50:00', 'Asia/Makassar'));
        $late = $this->order(['requested_date' => '2030-01-02']);
        $this->post('/admin/orders/'.$late->id.'/confirm', ['review_version' => 0])->assertSessionHasErrors('payment');
        $this->assertSame('pending_review', $late->fresh()->order_status);
        $this->post('/admin/orders/'.$late->id.'/review', ['review_version' => 0, 'delivery_fee' => 15000, 'note' => 'Moved', 'requested_date' => '2030-01-01'])->assertSessionHasErrors('requested_date');
        $this->post('/admin/orders/'.$late->id.'/review', ['review_version' => 0, 'delivery_fee' => 15000, 'note' => 'Customer agreed', 'requested_date' => '2030-01-03', 'requested_time' => '10:00'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('order_reviews', ['order_id' => $late->id, 'previous_schedule' => '2030-01-02', 'schedule' => '2030-01-03 10:00']);
        $this->confirm($late->fresh());
        $this->assertSame('pending', $late->fresh()->payment_status);
    }

    public function test_doku_failure_keeps_order_confirmed_and_retry_creates_a_new_attempt(): void
    {
        $this->fakeDoku([Http::response(['message' => ['Invalid Signature']], 401)]);
        $this->actingAs(User::factory()->create());
        $order = $this->order();
        $this->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertSessionHasErrors('payment');
        $this->assertSame(['confirmed', 'not_created'], [$order->fresh()->order_status, $order->fresh()->payment_status]);
        $this->assertSame('creation_failed', Payment::sole()->status);
        $this->assertSame('DOKU rejected the payment (HTTP 401: Invalid Signature)', Payment::sole()->last_error);

        $this->post('/admin/orders/'.$order->id.'/payments/retry')->assertSessionHasNoErrors();
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame([1 => 'creation_failed', 2 => 'pending'], Payment::orderBy('attempt')->pluck('status', 'attempt')->all());
        $this->assertNotSame(Payment::where('attempt', 1)->value('provider_request_id'), Payment::where('attempt', 2)->value('provider_request_id'));
    }

    public function test_verified_notifications_are_idempotent_and_never_regress_paid_orders(): void
    {
        $this->fakeDoku();
        $this->actingAs(User::factory()->create());
        $order = $this->order();
        $this->confirm($order);
        $payment = Payment::sole();

        $this->notify($payment, 'SUCCESS', [], 'HMACSHA256=forged')->assertUnauthorized();
        $this->notify($payment, 'SUCCESS', ['order' => ['amount' => 1000]])->assertOk()->assertJson(['status' => 'amount_mismatch']);
        $this->assertSame('pending', $order->fresh()->payment_status);
        // On DOKU Checkout a FAILED attempt keeps the link open: the customer may pick another method.
        $this->notify($payment, 'FAILED', ['channel' => ['id' => 'EMONEY_OVO']])->assertOk()->assertJson(['status' => 'no_change']);
        $this->notify($payment, 'SUCCESS')->assertOk()->assertJson(['status' => 'applied']);
        $this->notify($payment, 'SUCCESS')->assertOk()->assertJson(['status' => 'duplicate']);
        $this->notify($payment, 'PENDING', ['transaction' => ['date' => '2030-01-01T05:00:00Z']])->assertOk()->assertJson(['status' => 'ignored']);
        $this->notify($payment, 'EXPIRED')->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(['paid', 'confirmed'], [$order->fresh()->payment_status, $order->fresh()->order_status]);
        $this->assertNull($payment->fresh()->open_order_id);
        $this->assertSame('QRIS', $payment->fresh()->payment_type);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'payment.status_changed')->count());
        // mismatch, failed attempt, success, late pending, late expire; the duplicate success is not stored twice.
        $this->assertSame(5, DB::table('payment_events')->count());
        $this->signedNotification(json_encode(['order' => ['invoice_number' => 'unknown', 'amount' => 1], 'transaction' => ['status' => 'SUCCESS']]))
            ->assertOk()->assertJson(['status' => 'unknown_payment']);
        $this->post('/admin/orders/'.$order->id.'/review', ['review_version' => 0, 'delivery_fee' => 1, 'note' => 'Too late'])->assertSessionHasErrors('review');
    }

    public function test_expired_link_requires_reason_for_a_new_link_and_uses_latest_total(): void
    {
        $this->fakeDoku();
        $this->actingAs(User::factory()->create());
        $order = $this->order();
        $this->confirm($order);
        $this->post('/admin/orders/'.$order->id.'/payments/renew', ['reason' => 'Too early'])->assertSessionHasErrors('payment');
        $this->post('/admin/orders/'.$order->id.'/review', ['review_version' => 0, 'delivery_fee' => 1, 'note' => 'Locked'])->assertSessionHasErrors('review');
        $this->notify(Payment::sole(), 'EXPIRED')->assertJson(['status' => 'applied']);
        $this->assertSame('expired', $order->fresh()->payment_status);

        $this->post('/admin/orders/'.$order->id.'/review', ['review_version' => 0, 'delivery_fee' => 20000, 'note' => 'New courier quote'])->assertSessionHasNoErrors();
        $this->post('/admin/orders/'.$order->id.'/payments/renew', ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post('/admin/orders/'.$order->id.'/payments/renew', ['reason' => 'Customer asked again'])->assertSessionHasNoErrors();
        $latest = Payment::where('attempt', 2)->sole();
        $this->assertSame([180000, 'pending', 'Customer asked again'], [$latest->amount, $latest->status, $latest->reason]);
        $this->assertSame('pending', $order->fresh()->payment_status);
        // A late event for the old link never overwrites the newest link's status.
        $this->notify(Payment::where('attempt', 1)->sole(), 'PENDING')->assertJson(['status' => 'ignored']);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_checkout_links_without_a_transaction_expire_through_reconciliation(): void
    {
        $notFound = fn () => Http::response(['error' => ['message' => 'Order not found']], 404);
        $this->fakeDoku([], [$notFound(), $notFound(), Http::response([
            'order' => ['invoice_number' => 'ORL-300101-OTHER00001-P1', 'amount' => '175000'], 'channel' => ['id' => 'VIRTUAL_ACCOUNT_BCA'],
            'transaction' => ['status' => 'SUCCESS', 'date' => '2030-01-01T04:30:00Z'],
        ])]);
        $this->actingAs(User::factory()->create());
        $order = $this->order();
        $this->confirm($order);
        $payment = Payment::sole();
        $this->post('/admin/orders/'.$order->id.'/payments/'.$payment->id.'/check')->assertSessionHas('success', 'The customer has not paid on the DOKU page yet.');
        $this->assertSame('pending', $payment->fresh()->status);

        $this->travelTo(CarbonImmutable::parse('2030-01-02 12:10:00', 'Asia/Makassar'));
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(['expired', 'expired'], [$payment->fresh()->status, $order->fresh()->payment_status]);

        $other = $this->order(['order_code' => 'ORL-300101-OTHER00001']);
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'Asia/Makassar'));
        $this->confirm($other);
        $otherPayment = Payment::where('order_id', $other->id)->sole();
        $this->post('/admin/orders/'.$other->id.'/payments/'.$otherPayment->id.'/check')->assertSessionHas('success', 'Payment status updated from DOKU.');
        $this->assertSame(['paid', 'VIRTUAL_ACCOUNT_BCA'], [$other->fresh()->payment_status, $otherPayment->fresh()->payment_type]);
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'GET' && $request->url() === 'https://api-sandbox.doku.com/orders/v1/status/ORL-300101-OTHER00001-P1');
        $this->post('/admin/orders/'.$order->id.'/payments/'.$otherPayment->id.'/check')->assertNotFound();
    }

    public function test_fulfillment_follows_payment_and_method_rules(): void
    {
        $this->fakeDoku();
        $this->actingAs(User::factory()->create());
        $order = $this->order(['fulfillment_method' => 'pickup', 'delivery_fee' => 0, 'total' => 160000, 'delivery_address' => null]);
        $this->confirm($order);
        $status = fn (string $from, string $to) => $this->post('/admin/orders/'.$order->id.'/status', ['from' => $from, 'to' => $to]);
        $status('confirmed', 'processing')->assertSessionHasErrors('status');
        $this->notify(Payment::sole(), 'SUCCESS');
        $status('confirmed', 'ready')->assertSessionHasErrors('status');
        $status('confirmed', 'processing')->assertSessionHasNoErrors();
        $status('confirmed', 'processing')->assertSessionHasErrors('status');
        $status('processing', 'ready')->assertSessionHasNoErrors();
        $status('ready', 'delivering')->assertSessionHasErrors('status');
        $status('ready', 'completed')->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->order_status);
        $this->assertSame(['pending_review', 'confirmed', 'processing', 'ready', 'completed'], DB::table('order_status_histories')->where('order_id', $order->id)->orderBy('id')->pluck('to_status')->all());
        $this->post('/admin/orders/'.$order->id.'/cancel', ['from' => 'completed', 'cancel_reason' => 'No'])->assertSessionHasErrors('status');
    }

    public function test_cancellation_closes_open_links_and_paid_orders_need_an_admin(): void
    {
        $this->fakeDoku();
        $staff = User::factory()->create();
        $this->actingAs($staff);
        $open = $this->order();
        $this->confirm($open);
        $this->post('/admin/orders/'.$open->id.'/cancel', ['from' => 'confirmed', 'cancel_reason' => ''])->assertSessionHasErrors('cancel_reason');
        $this->post('/admin/orders/'.$open->id.'/cancel', ['from' => 'confirmed', 'cancel_reason' => 'Customer changed mind'])->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', 'cancelled'], [$open->fresh()->order_status, $open->fresh()->payment_status]);
        $openPayment = Payment::where('order_id', $open->id)->sole();
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api-sandbox.doku.com/checkout/v3/cancellations'
            && $request['order'] === ['invoice_number' => $openPayment->provider_order_id] && $request['payment'] === ['original_request_id' => $openPayment->provider_request_id]);
        $this->assertDatabaseHas('order_status_histories', ['order_id' => $open->id, 'to_status' => 'cancelled', 'note' => 'Customer changed mind']);
        // Money received after cancellation is still recorded so staff can refund it.
        $this->notify($openPayment, 'SUCCESS')->assertJson(['status' => 'applied']);
        $this->assertSame('paid', $open->fresh()->payment_status);

        $paid = $this->order(['order_code' => 'ORL-300101-PAID000001']);
        $this->confirm($paid);
        $this->notify(Payment::where('order_id', $paid->id)->sole(), 'SUCCESS');
        $this->post('/admin/orders/'.$paid->id.'/cancel', ['from' => 'confirmed', 'cancel_reason' => 'Out of stock'])->assertSessionHasErrors('status');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/orders/'.$paid->id.'/cancel', ['from' => 'confirmed', 'cancel_reason' => 'Out of stock'])->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', 'paid'], [$paid->fresh()->order_status, $paid->fresh()->payment_status]);
        $this->notify(Payment::where('order_id', $paid->id)->sole(), 'REFUNDED')->assertJson(['status' => 'applied']);
        $this->assertSame('refunded', $paid->fresh()->payment_status);
    }

    public function test_cancel_that_doku_refuses_warns_staff(): void
    {
        // Registered first so it wins over the default cancellation stub.
        Http::fake(['*/checkout/v3/cancellations' => Http::response(['message' => ['Order cannot be cancelled']], 400)]);
        $this->fakeDoku();
        $this->actingAs(User::factory()->create());
        $order = $this->order();
        $this->confirm($order);
        $this->post('/admin/orders/'.$order->id.'/cancel', ['from' => 'confirmed', 'cancel_reason' => 'Customer changed mind'])->assertSessionHasErrors('payment');
        $this->assertSame('cancelled', $order->fresh()->order_status);
    }

    public function test_without_doku_staff_confirm_and_record_manual_payments(): void
    {
        config(['services.doku.enabled' => false, 'services.erzap.enabled' => false]);
        Http::fake();
        $this->actingAs(User::factory()->create());
        $order = $this->order();
        $this->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('dokuEnabled', false)->has('manualMethods'));

        // Confirming does not call DOKU and creates no payment link.
        $this->confirm($order);
        $this->assertSame(['confirmed', 'not_created'], [$order->fresh()->order_status, $order->fresh()->payment_status]);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();

        $this->post('/admin/orders/'.$order->id.'/payments/manual', ['method' => 'crypto'])->assertSessionHasErrors('method');
        $this->post('/admin/orders/'.$order->id.'/payments/manual', ['method' => 'transfer', 'note' => 'BCA 10:15'])
            ->assertSessionHas('success', 'Payment recorded. Not sent to Erzap yet: the Erzap settings are not complete.');
        $payment = Payment::sole();
        $this->assertSame(['manual', 'paid', 175000, 'MANUAL-TRANSFER', 'BCA 10:15'], [$payment->provider, $payment->status, $payment->amount, $payment->payment_type, $payment->reason]);
        $this->assertSame('paid', $order->fresh()->payment_status);
        // Tried right away; it waits for the Erzap settings and the schedule retries it later.
        $this->assertDatabaseHas('integration_syncs', ['provider' => 'erzap', 'subject_id' => $order->id, 'status' => 'waiting_config']);
        // Paid orders cannot be marked twice, and fulfillment can start.
        $this->post('/admin/orders/'.$order->id.'/payments/manual', ['method' => 'cash'])->assertSessionHasErrors('payment');
        $this->post('/admin/orders/'.$order->id.'/status', ['from' => 'confirmed', 'to' => 'processing'])->assertSessionHasNoErrors();
    }

    public function test_mark_as_paid_sends_to_erzap_immediately(): void
    {
        config(['services.doku.enabled' => false, 'services.erzap' => [...config('services.erzap'), 'enabled' => true, 'base_url' => 'https://erzap.test:4443',
            'token' => 't', 'sales_user_id' => '54', 'default_outlet_id' => '1', 'send_order_code' => true]]);
        Http::fake(['erzap.test:4443/*' => Http::response(['status' => '1', 'message ' => ''])]);
        $this->actingAs(User::factory()->create());
        $order = $this->order();
        $order->items()->first()->product->update(['barcode' => '260810111525']);
        $this->confirm($order);
        $this->post('/admin/orders/'.$order->id.'/payments/manual', ['method' => 'cash'])->assertSessionHas('success', 'Payment recorded. The order is paid and was sent to Erzap.');
        $this->assertDatabaseHas('integration_syncs', ['subject_id' => $order->id, 'status' => 'synced']);
        Http::assertSent(fn (HttpRequest $request) => $request['shopping_carts']['pelanggan_payment_channel'] === 'MANUAL-CASH' && $request['shopping_carts']['konfirmasi_dari_bank'] === 'Manual');
    }

    public function test_disabled_staff_cannot_create_payments(): void
    {
        $this->fakeDoku();
        $order = $this->order();
        $this->actingAs(User::factory()->create(['is_active' => false]))->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertRedirect('/admin/login');
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }
}

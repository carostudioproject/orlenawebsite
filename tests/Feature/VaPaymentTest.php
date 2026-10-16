<?php

namespace Tests\Feature;

use App\Http\Controllers\Shop\PaymentPageController;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** DOKU_MODE=direct: BCA Virtual Account or QRIS on /bayar, chosen by the customer. */
class VaPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const VA = '   19008000000000012';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'Asia/Makassar'));
        Cache::flush();
        // Test-only key pairs (tests/fixtures): ours for the token, and the same pair standing in for DOKU's notification key.
        config(['services.doku' => [...config('services.doku'), 'enabled' => true, 'mode' => 'direct', 'methods' => ['va_bca', 'qris'],
            'client_id' => 'BRN-VA', 'secret_key' => 'va-secret', 'snap_client_secret' => null, 'is_production' => false,
            'va_bca_partner_service_id' => '19008', 'va_bca_customer_prefix' => '0',
            'qris_merchant_id' => 'MALL-1', 'qris_terminal_id' => 'TERM01', 'qris_postal_code' => '80361',
            'snap_private_key_path' => base_path('tests/fixtures/doku-test-private.pem'), 'snap_doku_public_key_path' => base_path('tests/fixtures/doku-test-public.pem'),
            'snap_token_path' => '/authorization/v1/access-token/b2b']]);
        config(['services.erzap.enabled' => false]);
    }

    /** VA status answers are taken in order, then stay pending. */
    private function fakeDoku(array $vaStatuses = []): void
    {
        $status = Http::sequence();
        foreach ($vaStatuses as $flag) {
            $status->push(['responseCode' => '2002600', 'virtualAccountData' => ['paymentFlagStatus' => $flag, 'paymentFlagReason' => ['english' => $flag === '00' ? 'Success' : 'Pending'],
                'virtualAccountNo' => self::VA, 'paidAmount' => ['value' => '175000.00', 'currency' => 'IDR']]]);
        }
        $status->whenEmpty(Http::response(['responseCode' => '2002600', 'virtualAccountData' => ['paymentFlagReason' => ['english' => 'Pending', 'indonesia' => 'Belum Terbayar'],
            'virtualAccountNo' => self::VA, 'paidAmount' => ['value' => '175000.00', 'currency' => 'IDR']]]));
        Http::fake([
            '*/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'b2b-token']),
            '*/transfer-va/create-va' => Http::response(['responseCode' => '2002700', 'virtualAccountData' => ['virtualAccountNo' => self::VA,
                'additionalInfo' => ['channel' => 'VIRTUAL_ACCOUNT_BCA', 'howToPayPage' => 'https://sandbox.doku.com/how-to-pay']]]),
            '*/transfer-va/status' => $status,
            '*/transfer-va/delete-va' => Http::response(['responseCode' => '2003100']),
            '*/qr/qr-mpm-generate' => Http::response(['responseCode' => '2004700', 'referenceNo' => 'DOKU-REF-1', 'qrContent' => '00020101021226670016ID.CO.QRIS.WWW']),
            '*/qr/qr-mpm-query' => Http::response(['responseCode' => '2005100', 'latestTransactionStatus' => '03', 'amount' => ['value' => '175000.00']]),
            '*/qr/qr-expire' => Http::response(['responseCode' => '2000500']),
        ]);
    }

    private function order(): Order
    {
        $category = Category::firstOrCreate(['name' => 'Brownies']);
        $product = Product::create(['category_id' => $category->id, 'sku' => 'SKU-'.Str::random(5), 'name' => 'Berry', 'price' => 80000, 'is_active' => true]);
        $outlet = Outlet::firstOrCreate(['code' => 'T'], ['name' => 'Test Outlet', 'address' => 'Jl', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Sinta Private', 'whatsapp' => '6281234567890', 'email' => null]);
        $order = Order::create([
            'order_code' => 'ORL-300101-'.strtoupper(Str::random(10)), 'checkout_key' => (string) Str::uuid(), 'owner_hash' => str_repeat('a', 64), 'request_hash' => str_repeat('b', 64),
            'customer_id' => $customer->id, 'outlet_id' => $outlet->id, 'outlet_name_snapshot' => $outlet->name, 'fulfillment_method' => 'delivery',
            'requested_date' => '2030-01-03', 'delivery_address' => 'Secret address', 'subtotal' => 160000, 'delivery_fee' => 15000, 'total' => 175000,
            'order_status' => 'pending_review', 'payment_status' => 'not_created',
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_name_snapshot' => 'Berry', 'category_snapshot' => 'Brownies', 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 80000, 'quantity' => 2, 'subtotal' => 160000]);

        return $order;
    }

    /** Staff confirm; with two methods no link is created yet. Then the customer picks one on /bayar. */
    private function confirm(Order $order, string $method = 'va_bca'): Payment
    {
        $this->actingAs(User::factory()->create())->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertSessionHasNoErrors();
        auth()->logout();
        $this->assertSame(0, Payment::where('order_id', $order->id)->count());
        $this->post('/bayar/'.$order->order_code.'/metode', ['method' => $method])->assertRedirect('/bayar/'.$order->order_code)->assertSessionHasNoErrors();

        return Payment::where('order_id', $order->id)->latest('attempt')->firstOrFail();
    }

    public function test_confirming_waits_for_the_customer_to_choose_the_method(): void
    {
        $this->fakeDoku();
        $order = $this->order();
        $admin = User::factory()->create();
        $this->actingAs($admin)->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('customerChooses', true)->has('order.payment_due_at'));
        auth()->logout();
        $order->refresh();
        $this->assertSame(['confirmed', 'not_created', '2030-01-01 14:00'], [$order->order_status, $order->payment_status, $order->payment_due_at->setTimezone('Asia/Makassar')->format('Y-m-d H:i')]);
        $this->assertSame(0, Payment::count());
        Http::assertNothingSent();

        $this->get('/bayar/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('payment', null)->has('methods', 2)->where('dueAt', $order->payment_due_at->toIso8601String()));
        $this->assertSame(url('/bayar/'.$order->order_code), app(PaymentPageController::class) ? url('/bayar/'.$order->order_code) : null);
    }

    public function test_after_the_deadline_the_customer_cannot_choose_and_staff_can_reopen(): void
    {
        $this->fakeDoku();
        $order = $this->order();
        $admin = User::factory()->create();
        $this->actingAs($admin)->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertSessionHasNoErrors();
        auth()->logout();
        $this->travel(25)->hours();
        $this->get('/bayar/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('payment', null)->where('methods', []));
        $this->post('/bayar/'.$order->order_code.'/metode', ['method' => 'va_bca'])->assertSessionHasErrors('payment');
        $this->assertSame(0, Payment::count());

        $this->actingAs($admin)->post('/admin/orders/'.$order->id.'/payments/renew', ['reason' => 'Customer asked again'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->assertTrue($order->fresh()->payment_due_at->isFuture());
        $this->get('/bayar/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->has('methods', 2));
    }

    public function test_with_one_method_confirming_creates_the_code_right_away(): void
    {
        $this->fakeDoku();
        config(['services.doku.methods' => ['va_bca']]);
        $order = $this->order();
        $this->actingAs(User::factory()->create())->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertSessionHasNoErrors();
        $this->assertSame(['doku_va', 'pending'], [Payment::sole()->provider, Payment::sole()->status]);
        $this->get('/bayar/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->has('payment.va')->where('methods', [['value' => 'va_bca', 'label' => 'Virtual Account BCA']]));
    }

    public function test_choosing_va_creates_a_signed_bca_va_that_our_page_shows_with_the_method_choice(): void
    {
        $this->fakeDoku();
        $order = $this->order();
        $payment = $this->confirm($order);
        $this->assertSame(['doku_va', 'va_bca', 'pending', self::VA, url('/bayar/'.$order->order_code)],
            [$payment->provider, $payment->method, $payment->status, $payment->va_number, $payment->payment_url]);

        Http::assertSent(function (HttpRequest $request) use ($order) {
            if (! str_ends_with($request->url(), '/transfer-va/create-va')) {
                return false;
            }
            $toSign = 'POST:/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va:b2b-token:'.strtolower(hash('sha256', $request->body())).':'.$request->header('X-TIMESTAMP')[0];

            return $request->header('X-SIGNATURE') === [base64_encode(hash_hmac('sha512', $toSign, 'va-secret', true))] && $request->header('CHANNEL-ID') === ['H2H']
                && $request['partnerServiceId'] === '   19008' && $request['customerNo'] === '0' && $request['virtualAccountNo'] === '   190080'
                && $request['trxId'] === $order->order_code.'-P1' && $request['totalAmount'] === ['value' => '175000.00', 'currency' => 'IDR']
                && $request['virtualAccountTrxType'] === 'C' && $request['additionalInfo']['channel'] === 'VIRTUAL_ACCOUNT_BCA'
                && $request['virtualAccountName'] === 'Sinta Private' && $request['expiredDate'] === '2030-01-01T13:00:00+07:00';
        });

        $this->get('/bayar/'.$order->order_code)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Shop/Payment')
                ->where('payment.va', '19008000000000012')->where('payment.method', 'va_bca')->where('payment.qr', null)->where('payment.checkout_url', null)
                ->where('methods', [['value' => 'va_bca', 'label' => 'Virtual Account BCA'], ['value' => 'qris', 'label' => 'QRIS']]))
            ->assertDontSee('Sinta Private')->assertDontSee('Secret address');
    }

    public function test_the_customer_can_switch_to_qris_which_closes_the_va_and_keeps_the_deadline(): void
    {
        $this->fakeDoku();
        $order = $this->order();
        $va = $this->confirm($order);

        $this->post('/bayar/'.$order->order_code.'/metode', ['method' => 'qris'])->assertRedirect('/bayar/'.$order->order_code)->assertSessionHasNoErrors();
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/transfer-va/delete-va') && $request->method() === 'DELETE'
            && $request['virtualAccountNo'] === self::VA && $request['partnerServiceId'] === '   19008' && $request['customerNo'] === '000000000012' && $request['trxId'] === $va->provider_order_id);
        $qris = Payment::where('order_id', $order->id)->latest('attempt')->firstOrFail();
        $this->assertSame(['cancelled', null], [$va->fresh()->status, $va->fresh()->open_order_id]);
        $this->assertSame(['doku_qris', 'qris', 'pending', 2, $va->expires_at->toIso8601String(), 'Pelanggan memilih QRIS'],
            [$qris->provider, $qris->method, $qris->status, $qris->attempt, $qris->expires_at->toIso8601String(), $qris->reason]);
        $this->assertSame(['confirmed', 'pending'], [$order->fresh()->order_status, $order->fresh()->payment_status]);
        $this->get('/bayar/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('payment.method', 'qris')->has('payment.qr'));

        // Choosing the open method again changes nothing; unknown methods are refused.
        $this->post('/bayar/'.$order->order_code.'/metode', ['method' => 'qris'])->assertSessionHasNoErrors();
        $this->post('/bayar/'.$order->order_code.'/metode', ['method' => 'gopay'])->assertSessionHasErrors('method');
        $this->assertSame(2, Payment::where('order_id', $order->id)->count());

        // And back to VA BCA: the QRIS is expired at DOKU.
        $this->post('/bayar/'.$order->order_code.'/metode', ['method' => 'va_bca'])->assertSessionHasNoErrors();
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/qr/qr-expire') && $request['partnerReferenceNo'] === $qris->provider_order_id);
        $this->assertSame(['doku_va', 3], [Payment::where('order_id', $order->id)->latest('attempt')->first()->provider, Payment::where('order_id', $order->id)->max('attempt')]);
    }

    public function test_switching_after_the_va_was_paid_records_the_payment_instead(): void
    {
        $this->fakeDoku(['00']);
        $order = $this->order();
        $va = $this->confirm($order);

        $this->post('/bayar/'.$order->order_code.'/metode', ['method' => 'qris'])->assertSessionHasErrors('payment');
        $this->assertSame(['paid', 'paid', 'VA-BCA'], [$order->fresh()->payment_status, $va->fresh()->status, $va->fresh()->payment_type]);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        Http::assertNotSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/transfer-va/delete-va'));
    }

    public function test_polling_marks_the_order_paid_when_doku_reports_the_va_paid(): void
    {
        $this->fakeDoku(['01', '00']);
        $order = $this->order();
        $this->confirm($order);
        $this->getJson('/bayar/'.$order->order_code.'/status')->assertJson(['payment_status' => 'pending']);
        $this->travel(6)->seconds();
        $this->getJson('/bayar/'.$order->order_code.'/status')->assertJson(['payment_status' => 'paid', 'payment' => null, 'methods' => []]);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/transfer-va/status') && $request['virtualAccountNo'] === self::VA);
    }

    public function test_signed_notifications_are_applied_and_unsigned_ones_only_trigger_a_status_check(): void
    {
        $this->fakeDoku();
        $order = $this->order();
        $va = $this->confirm($order);
        $body = ['partnerServiceId' => '   19008', 'customerNo' => '000000000012', 'virtualAccountNo' => self::VA, 'virtualAccountName' => 'Sinta Private',
            'trxId' => $va->provider_order_id, 'paymentRequestId' => 'PAY-1', 'paidAmount' => ['value' => '175000.00', 'currency' => 'IDR'],
            'additionalInfo' => ['channel' => 'VIRTUAL_ACCOUNT_BCA'], 'trxDateTime' => '2030-01-01T12:20:00+07:00'];

        // Forged: no valid signature, so DOKU is asked and still says pending.
        $this->postJson('/webhooks/doku-va', $body, ['X-TIMESTAMP' => '2030-01-01T12:20:00+07:00', 'X-SIGNATURE' => base64_encode('forged')])
            ->assertOk()->assertJson(['responseCode' => '2002500', 'virtualAccountData' => ['virtualAccountNo' => self::VA]]);
        $this->assertSame('pending', $order->fresh()->payment_status);

        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        $timestamp = '2030-01-01T12:20:05+07:00';
        openssl_sign('POST:/webhooks/doku-va:'.strtolower(hash('sha256', $json)).':'.$timestamp, $signature,
            openssl_pkey_get_private((string) file_get_contents(base_path('tests/fixtures/doku-test-private.pem'))), OPENSSL_ALGO_SHA256);
        $this->call('POST', '/webhooks/doku-va', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE' => base64_encode($signature)], $json)->assertOk()->assertJson(['status' => 'applied']);
        $this->assertSame(['paid', 'VA-BCA'], [$order->fresh()->payment_status, $va->fresh()->payment_type]);
        $this->assertDatabaseHas('integration_syncs', ['provider' => 'erzap', 'subject_id' => $order->id]);
        $this->postJson('/webhooks/doku-va', ['trxId' => 'unknown'])->assertOk()->assertJson(['status' => 'unknown_payment']);
    }

    public function test_doku_gets_a_token_from_our_token_url_and_signs_the_notification_with_it(): void
    {
        $this->fakeDoku();
        $order = $this->order();
        $va = $this->confirm($order);
        $dokuKey = openssl_pkey_get_private((string) file_get_contents(base_path('tests/fixtures/doku-test-private.pem')));
        $timestamp = '2030-01-01T12:21:00+07:00';

        // Only DOKU (its private key, our Client ID) gets a token.
        openssl_sign('BRN-OTHER|'.$timestamp, $wrongClient, $dokuKey, OPENSSL_ALGO_SHA256);
        $this->postJson('/authorization/v1/access-token/b2b', ['grantType' => 'client_credentials'], ['X-CLIENT-KEY' => 'BRN-OTHER', 'X-TIMESTAMP' => $timestamp, 'X-SIGNATURE' => base64_encode($wrongClient)])
            ->assertStatus(401)->assertJson(['responseCode' => '4017300']);
        $this->postJson('/authorization/v1/access-token/b2b', ['grantType' => 'client_credentials'], ['X-CLIENT-KEY' => 'BRN-VA', 'X-TIMESTAMP' => $timestamp, 'X-SIGNATURE' => base64_encode('forged')])
            ->assertStatus(401);
        openssl_sign('BRN-VA|'.$timestamp, $signed, $dokuKey, OPENSSL_ALGO_SHA256);
        $token = $this->postJson('/authorization/v1/access-token/b2b', ['grantType' => 'client_credentials'], ['X-CLIENT-KEY' => 'BRN-VA', 'X-TIMESTAMP' => $timestamp, 'X-SIGNATURE' => base64_encode($signed)])
            ->assertOk()->assertJson(['responseCode' => '2007300', 'tokenType' => 'Bearer', 'expiresIn' => '900'])->json('accessToken');

        $body = ['partnerServiceId' => '   19008', 'customerNo' => '000000000012', 'virtualAccountNo' => self::VA, 'virtualAccountName' => 'Sinta Private',
            'trxId' => $va->provider_order_id, 'paymentRequestId' => 'PAY-2', 'paidAmount' => ['value' => '175000.00', 'currency' => 'IDR'], 'trxDateTime' => '2030-01-01T12:21:30+07:00'];
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        $sign = fn (string $withToken) => base64_encode(hash_hmac('sha512', 'POST:/webhooks/doku-va:'.$withToken.':'.strtolower(hash('sha256', $json)).':'.$timestamp, 'va-secret', true));
        $send = fn (string $withToken, string $signature) => $this->call('POST', '/webhooks/doku-va', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$withToken, 'HTTP_X_TIMESTAMP' => $timestamp, 'HTTP_X_SIGNATURE' => $signature], $json);
        // A token we never issued is not trusted: the status is read back from DOKU (still pending).
        $send('made-up-token', $sign('made-up-token'))->assertOk();
        $this->assertSame('pending', $order->fresh()->payment_status);
        $send($token, $sign($token))->assertOk()->assertJson(['status' => 'applied']);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_demo_mode_offers_va_and_qris_without_calling_doku(): void
    {
        Http::fake();
        config(['services.doku.enabled' => false, 'services.doku.mode' => 'demo']);
        $order = $this->order();
        $va = $this->confirm($order);
        $this->assertSame(['demo', 'va_bca'], [$va->provider, $va->method]);
        $this->get('/bayar/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('payment.demo', true)->has('payment.va')->has('methods', 2));

        $this->post('/bayar/'.$order->order_code.'/metode', ['method' => 'qris'])->assertSessionHasNoErrors();
        $this->get('/bayar/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('payment.method', 'qris')->has('payment.qr'));
        $this->post('/bayar/'.$order->order_code.'/simulasi')->assertRedirect('/bayar/'.$order->order_code);
        $this->assertSame(['paid', 'QRIS-DEMO'], [$order->fresh()->payment_status, Payment::where('order_id', $order->id)->latest('attempt')->first()->payment_type]);
        Http::assertNothingSent();
    }

    public function test_missing_va_settings_fail_safely_and_the_customer_can_try_again(): void
    {
        $this->fakeDoku();
        config(['services.doku.va_bca_partner_service_id' => null]);
        $order = $this->order();
        $this->actingAs(User::factory()->create())->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertSessionHasNoErrors();
        auth()->logout();
        $this->post('/bayar/'.$order->order_code.'/metode', ['method' => 'va_bca'])->assertSessionHasErrors('method');
        $this->assertSame(['creation_failed', 'DOKU BCA VA settings are incomplete (va_bca_partner_service_id)'], [Payment::sole()->status, Payment::sole()->last_error]);
        Http::assertNothingSent();

        // The order is still waiting for a choice: QRIS works.
        $this->get('/bayar/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('payment', null)->has('methods', 2));
        $this->post('/bayar/'.$order->order_code.'/metode', ['method' => 'qris'])->assertSessionHasNoErrors();
        $this->assertSame(['doku_qris', 'pending'], [Payment::where('order_id', $order->id)->latest('attempt')->first()->provider, $order->fresh()->payment_status]);
    }
}

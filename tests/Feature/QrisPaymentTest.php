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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QrisPaymentTest extends TestCase
{
    use RefreshDatabase;

    private string $keyPath;

    private string $publicKey;

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
        // Test-only key pair (tests/fixtures), never used outside tests.
        $this->keyPath = base_path('tests/fixtures/doku-test-private.pem');
        $this->publicKey = (string) file_get_contents(base_path('tests/fixtures/doku-test-public.pem'));
        Cache::flush();
        config(['services.doku' => [...config('services.doku'), 'enabled' => true, 'mode' => 'qris', 'client_id' => 'BRN-QRIS', 'secret_key' => 'qris-secret',
            'snap_client_secret' => null, 'is_production' => false, 'qris_merchant_id' => 'MALL-1', 'qris_terminal_id' => 'TERM01', 'qris_postal_code' => '80361',
            'snap_private_key_path' => $this->keyPath, 'snap_token_path' => '/authorization/v1/access-token/b2b']]);
        config(['services.erzap.enabled' => false]);
    }

    /** Token, generate and query answers; query statuses are taken in order, then stay "03" (pending). */
    private function fakeDoku(array $queryStatuses = []): void
    {
        $query = Http::sequence();
        foreach ($queryStatuses as $status) {
            $query->push(['responseCode' => '2005100', 'latestTransactionStatus' => $status, 'amount' => ['value' => '175000.00', 'currency' => 'IDR'],
                'paidTime' => '2030-01-01T12:30:00+07:00', 'additionalInfo' => ['issuerName' => 'GOPAY']]);
        }
        $query->whenEmpty(Http::response(['responseCode' => '2005100', 'latestTransactionStatus' => '03', 'amount' => ['value' => '175000.00', 'currency' => 'IDR']]));
        Http::fake([
            '*/authorization/v1/access-token/b2b' => Http::response(['responseCode' => '2007300', 'accessToken' => 'b2b-token', 'tokenType' => 'Bearer', 'expiresIn' => '900']),
            '*/qr/qr-mpm-generate' => Http::response(['responseCode' => '2004700', 'referenceNo' => 'DOKU-REF-1', 'partnerReferenceNo' => 'x', 'qrContent' => '00020101021226670016ID.CO.QRIS.WWW']),
            '*/qr/qr-mpm-query' => $query,
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

    private function confirm(Order $order): Payment
    {
        $this->actingAs(User::factory()->create())->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertSessionHasNoErrors();
        auth()->logout();

        return Payment::where('order_id', $order->id)->latest('attempt')->firstOrFail();
    }

    public function test_confirming_creates_a_signed_qris_and_our_payment_page_shows_it(): void
    {
        $this->fakeDoku();
        $order = $this->order();
        $payment = $this->confirm($order);
        $this->assertSame(['doku_qris', 'pending', 'DOKU-REF-1', url('/bayar/'.$order->order_code), '00020101021226670016ID.CO.QRIS.WWW'],
            [$payment->provider, $payment->status, $payment->provider_request_id, $payment->payment_url, $payment->qr_content]);

        // Token: SHA256withRSA over "clientId|timestamp", verifiable with our public key.
        Http::assertSent(function (HttpRequest $request) {
            if (! str_ends_with($request->url(), '/authorization/v1/access-token/b2b')) {
                return false;
            }

            return $request->header('X-CLIENT-KEY') === ['BRN-QRIS'] && $request['grantType'] === 'client_credentials'
                && openssl_verify('BRN-QRIS|'.$request->header('X-TIMESTAMP')[0], base64_decode($request->header('X-SIGNATURE')[0]), $this->publicKey, OPENSSL_ALGO_SHA256) === 1;
        });
        // Generate: HMAC-SHA512 symmetric signature with the access token.
        Http::assertSent(function (HttpRequest $request) use ($order) {
            if (! str_ends_with($request->url(), '/qr/qr-mpm-generate')) {
                return false;
            }
            $toSign = 'POST:/snap-adapter/b2b/v1.0/qr/qr-mpm-generate:b2b-token:'.strtolower(hash('sha256', $request->body())).':'.$request->header('X-TIMESTAMP')[0];

            return $request->header('Authorization') === ['Bearer b2b-token'] && $request->header('CHANNEL-ID') === ['H2H']
                && $request->header('X-SIGNATURE') === [base64_encode(hash_hmac('sha512', $toSign, 'qris-secret', true))]
                && $request['partnerReferenceNo'] === $order->order_code.'-P1' && $request['amount'] === ['value' => '175000.00', 'currency' => 'IDR']
                && $request['merchantId'] === 'MALL-1' && $request['terminalId'] === 'TERM01' && $request['additionalInfo'] === ['postalCode' => '80361', 'feeType' => '1'];
        });

        $this->get('/bayar/'.$order->order_code)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page->component('Shop/Payment')
                ->where('payment.qr', '00020101021226670016ID.CO.QRIS.WWW')->where('payment.amount', 175000)
                ->missing('order.customer')->missing('order.delivery_address'))
            ->assertDontSee('Sinta Private')->assertDontSee('Secret address');
        $this->get('/bayar/ORL-NOPE')->assertNotFound();
    }

    public function test_polling_marks_the_order_paid_once_doku_reports_success(): void
    {
        $this->fakeDoku(['03', '00']);
        $order = $this->order();
        $this->confirm($order);

        $this->getJson('/bayar/'.$order->order_code.'/status')->assertOk()->assertJson(['payment_status' => 'pending']);
        // Within 5 seconds DOKU is not asked again.
        $this->getJson('/bayar/'.$order->order_code.'/status')->assertJson(['payment_status' => 'pending']);
        // Token (cached), generate and one query.
        Http::assertSentCount(3);

        $this->travel(6)->seconds();
        $this->getJson('/bayar/'.$order->order_code.'/status')->assertJson(['payment_status' => 'paid', 'payment' => null]);
        $payment = Payment::sole();
        $this->assertSame(['paid', 'QRIS-GOPAY'], [$payment->status, $payment->payment_type]);
        $this->assertDatabaseHas('integration_syncs', ['provider' => 'erzap', 'subject_id' => $order->id]);
    }

    public function test_notifications_only_trigger_a_status_query(): void
    {
        $this->fakeDoku(['03', '00']);
        $order = $this->order();
        $payment = $this->confirm($order);

        // A forged "paid" body is ignored: DOKU still says pending.
        $this->postJson('/webhooks/doku-qris', ['originalPartnerReferenceNo' => $payment->provider_order_id, 'latestTransactionStatus' => '00'])
            ->assertOk()->assertJson(['responseCode' => '2005200']);
        $this->assertSame('pending', $order->fresh()->payment_status);

        $this->postJson('/webhooks/doku-qris', ['originalReferenceNo' => 'DOKU-REF-1'])->assertOk()->assertJson(['status' => 'applied']);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->postJson('/webhooks/doku-qris', ['partnerReferenceNo' => 'unknown'])->assertOk()->assertJson(['status' => 'unknown_payment']);
    }

    public function test_cancelling_an_order_expires_its_qris(): void
    {
        $this->fakeDoku();
        $order = $this->order();
        $payment = $this->confirm($order);
        $this->actingAs(User::factory()->create())->post('/admin/orders/'.$order->id.'/cancel', ['from' => 'confirmed', 'cancel_reason' => 'Customer changed mind'])->assertSessionHasNoErrors();
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/qr/qr-expire')
            && $request['referenceNo'] === 'DOKU-REF-1' && $request['partnerReferenceNo'] === $payment->provider_order_id);
        $this->get('/bayar/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('payment', null));
    }

    public function test_demo_mode_shows_a_sample_qr_and_simulates_payment_without_doku(): void
    {
        Http::fake();
        config(['services.doku.enabled' => false, 'services.doku.mode' => 'demo']);
        $order = $this->order();
        $this->actingAs(User::factory()->create())->get('/admin/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('dokuEnabled', true));
        $payment = $this->confirm($order);
        $this->assertSame(['demo', 'pending', url('/bayar/'.$order->order_code)], [$payment->provider, $payment->status, $payment->payment_url]);
        $this->get('/bayar/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->where('payment.demo', true)->has('payment.qr'));

        // Polling never asks DOKU for demo payments.
        $this->getJson('/bayar/'.$order->order_code.'/status')->assertJson(['payment_status' => 'pending']);
        $this->post('/bayar/'.$order->order_code.'/simulasi')->assertRedirect('/bayar/'.$order->order_code);
        $this->assertSame(['paid', 'QRIS-DEMO'], [$order->fresh()->payment_status, $payment->fresh()->payment_type]);
        $this->assertDatabaseHas('integration_syncs', ['provider' => 'erzap', 'subject_id' => $order->id]);
        $this->post('/bayar/'.$order->order_code.'/simulasi')->assertNotFound();
        Http::assertNothingSent();

        // Outside demo mode the simulate endpoint does not exist.
        config(['services.doku.mode' => 'qris']);
        $this->post('/bayar/'.$order->order_code.'/simulasi')->assertNotFound();
    }

    public function test_missing_qris_settings_fail_safely(): void
    {
        $this->fakeDoku();
        config(['services.doku.qris_merchant_id' => null]);
        $order = $this->order();
        $this->actingAs(User::factory()->create())->post('/admin/orders/'.$order->id.'/confirm', ['review_version' => 0])->assertSessionHasErrors('payment');
        $this->assertSame(['creation_failed', 'DOKU QRIS settings are incomplete (qris_merchant_id)'], [Payment::sole()->status, Payment::sole()->last_error]);
        Http::assertNothingSent();
    }
}

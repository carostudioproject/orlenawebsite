<?php

namespace Tests\Feature;

use App\Actions\Integrations\ErzapSync;
use App\Models\Category;
use App\Models\Customer;
use App\Models\IntegrationSync;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** URLs Erzap calls on the website (Erzap API v1.2, section 2). */
class ErzapWebServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'erzap-inbound-secret';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.erzap.inbound_token' => self::TOKEN, 'services.erzap.enabled' => false]);
    }

    private function erzap(string $endpoint, array $body, ?string $token = self::TOKEN)
    {
        return $this->postJson('/web_service/'.$endpoint.'/new', $body, $token === null ? [] : ['X-Erzap-Token' => $token]);
    }

    private function product(array $attributes = []): Product
    {
        $category = Category::firstOrCreate(['name' => 'Brownies']);

        return Product::create([
            'category_id' => $category->id, 'sku' => 'SKU-'.Str::random(5), 'name' => 'Berry', 'variant' => 'Fullsize',
            'barcode' => '200207230802', 'price' => 80000, 'is_active' => true, ...$attributes,
        ]);
    }

    private function paidOrder(Product $product, string $created = '2030-01-01 10:00:00'): Order
    {
        $outlet = Outlet::firstOrCreate(['code' => 'T'], ['name' => 'Test Outlet', 'address' => 'Jl', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Sinta', 'whatsapp' => '6281234567890', 'email' => null]);
        $order = Order::create([
            'order_code' => 'ORL-300101-'.strtoupper(Str::random(10)), 'checkout_key' => (string) Str::uuid(), 'owner_hash' => str_repeat('a', 64), 'request_hash' => str_repeat('b', 64),
            'customer_id' => $customer->id, 'outlet_id' => $outlet->id, 'outlet_name_snapshot' => $outlet->name, 'fulfillment_method' => 'pickup',
            'requested_date' => '2030-01-03', 'subtotal' => 160000, 'delivery_fee' => 0, 'total' => 160000, 'order_status' => 'confirmed', 'payment_status' => 'paid',
        ]);
        $order->forceFill(['created_at' => $created])->save();
        $order->items()->create(['product_id' => $product->id, 'product_name_snapshot' => 'Berry', 'category_snapshot' => 'Brownies', 'sku_snapshot' => $product->sku,
            'unit_price_snapshot' => 80000, 'quantity' => 2, 'subtotal' => 160000]);
        ErzapSync::queue($order);

        return $order;
    }

    public function test_calls_without_the_right_token_are_rejected(): void
    {
        $this->erzap('check_pesanan', ['check_pesanan' => true], null)->assertUnauthorized();
        $this->erzap('check_pesanan', ['check_pesanan' => true], 'wrong')->assertUnauthorized()->assertJson(['olzap' => ['status' => '0']]);
        config(['services.erzap.inbound_token' => null]);
        $this->erzap('check_pesanan', ['check_pesanan' => true], '')->assertUnauthorized();
        // Stateless: no CSRF token or session needed.
        config(['services.erzap.inbound_token' => self::TOKEN]);
        $this->erzap('check_pesanan', ['check_pesanan' => true])->assertOk()->assertJson(['status' => 1]);
    }

    public function test_product_data_updates_prices_and_creates_new_products_inactive(): void
    {
        $existing = $this->product();
        $this->erzap('import_produk_json', ['erzap' => ['produks' => [
            ['barcode' => '200207230802', 'nama' => 'Renamed in Erzap', 'harga_jual' => '85000.0', 'harga_diskon' => '', 'is_active' => true, 'create_data_if_not_exist' => true],
            ['barcode' => '960505361196', 'nama' => 'Choco Mini', 'nama_web' => 'Choco', 'harga_jual' => '0.0', 'is_active' => true, 'create_data_if_not_exist' => true,
                'kategori_nama' => 'Mini Size', 'produk_ukuran_nama' => 'Mini', 'overview' => '<b>Rich</b> chocolate'],
            ['barcode' => '111', 'nama' => 'Not wanted', 'harga_jual' => '1000.0', 'create_data_if_not_exist' => false],
            ['nama' => 'No barcode'],
        ]]])->assertOk()->assertJson(['olzap' => ['status' => 1, 'notice' => 'produk tanpa barcode dilewati']]);

        // Erzap owns the price; the website keeps its name and visibility.
        $this->assertSame(['Berry', 85000, true], [$existing->fresh()->name, $existing->fresh()->price, $existing->fresh()->is_active]);
        $new = Product::where('barcode', '960505361196')->sole();
        $this->assertSame(['Choco', 'Mini', 0, false, 'Mini Size', 'Rich chocolate'], [$new->name, $new->variant, $new->price, $new->is_active, $new->category->name, $new->description]);
        $this->assertDatabaseMissing('products', ['barcode' => '111']);

        // Erzap can hide a product, written with Erzap's "=>" notation.
        $this->call('POST', '/web_service/import_produk_json/new', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ERZAP_TOKEN' => self::TOKEN],
            '{"erzap"=>{"produks"=>[{"barcode"=>"200207230802","harga_jual"=>"85000.0","is_active"=>false}]}}')->assertOk();
        $this->assertFalse($existing->fresh()->is_active);
    }

    public function test_prices_use_the_web_price_and_discounts_and_stock_is_ignored(): void
    {
        $product = $this->product();
        $this->erzap('sinkronisasi_harga', ['erzap' => ['produks' => [
            ['barcode' => '200207230802', 'harga_jual' => '100000.0', 'harga_diskon' => '90000.0', 'harga_jual_web' => '105000.0', 'harga_diskon_web' => ''],
            ['barcode' => 'unknown', 'harga_jual' => '1.0'],
        ]]])->assertOk()->assertExactJson(['olzap' => ['status' => 'sukses', 'msg' => '']]);
        $this->assertSame(90000, $product->fresh()->price);

        $this->erzap('sinkronisasi_harga', ['erzap' => ['produks' => [['barcode' => '200207230802', 'harga_jual' => '100000.0', 'harga_diskon' => '0.0', 'harga_jual_web' => '105000.0']]]]);
        $this->assertSame(105000, $product->fresh()->price);

        $this->erzap('sinkronisasi_stok', ['erzap' => ['produks' => [['barcode' => '200207230802', 'stok' => '0.0', 'harga_jual' => '1000.0']]]])
            ->assertOk()->assertJson(['olzap' => ['status' => 'sukses']]);
        $this->assertSame([105000, true], [$product->fresh()->price, $product->fresh()->is_active]);
    }

    public function test_erzap_pulls_paid_orders_and_marks_them_saved(): void
    {
        $product = $this->product();
        $newer = $this->paidOrder($product, '2030-01-01 11:00:00');
        $older = $this->paidOrder($product, '2030-01-01 09:00:00');
        $sent = $this->paidOrder($product);
        IntegrationSync::where('subject_id', $sent->id)->update(['status' => 'synced']);
        $this->paidOrder($this->product(['barcode' => null, 'sku' => 'NOBAR']));

        $carts = $this->erzap('check_pesanan', ['check_pesanan' => true])->assertOk()->assertJson(['status' => 1, 'notice' => ''])->json('data.shopping_carts');
        $this->assertSame([$older->order_code, $newer->order_code], array_column($carts, 'kode'));
        $this->assertSame([['harga_satuan' => '80000.0', 'jumlah' => '2.0', 'tgl_check_in' => null, 'barcode_produk' => '200207230802']], $carts[0]['shopping_cart_details']);
        $this->assertArrayNotHasKey('token_erzap', $carts[0]);

        $this->erzap('update_status_pesanan', ['update_status_shopping_cart' => 5, 'kode_shopping_cart' => $older->order_code])
            ->assertOk()->assertExactJson(['notice' => 'Status pesanan sukses diupdate', 'status' => 1]);
        $this->assertSame(['synced', $older->order_code], [IntegrationSync::where('subject_id', $older->id)->value('status'), IntegrationSync::where('subject_id', $older->id)->value('external_ref')]);
        $this->assertSame([$newer->order_code], array_column($this->erzap('check_pesanan', ['check_pesanan' => true])->json('data.shopping_carts'), 'kode'));

        $this->erzap('update_status_pesanan', ['update_status_shopping_cart' => 5, 'kode_shopping_cart' => 'ORL-NOPE'])
            ->assertOk()->assertJson(['status' => 0, 'notice' => 'Data shopping cart dengan kode ORL-NOPE tidak ditemukan']);
    }

    public function test_images_fill_products_without_a_photo_only(): void
    {
        Storage::fake('public');
        $withoutPhoto = $this->product();
        $withPhoto = $this->product(['barcode' => '960505361196', 'sku' => 'SKU-2', 'image' => '/storage/content/staff.webp']);
        // 1×1 PNG; a real image, so no GD is needed to build it.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        $file = fn (string $name) => UploadedFile::fake()->createWithContent($name, $png);
        $meta = ['erzap' => ['produks' => [
            ['barcode' => '200207230802', 'images_produk' => [['ref' => '1', 'filename' => 'back.png', 'is_cover' => false], ['ref' => '2', 'filename' => 'front.png', 'is_cover' => true]]],
            ['barcode' => '960505361196', 'images_produk' => [['ref' => '3', 'filename' => 'other.png', 'is_cover' => true]]],
        ]]];

        $this->withHeaders(['X-Erzap-Token' => self::TOKEN])->post('/web_service/sinkronisasi_gambar/new', [
            'erzap_json' => json_encode($meta), 'file_1' => $file('back.png'), 'file_2' => $file('front.png'), 'file_3' => $file('other.png'),
        ])->assertOk()->assertExactJson(['olzap' => ['status' => 'sukses', 'msg' => '']]);
        $image = (string) $withoutPhoto->fresh()->image;
        $this->assertStringStartsWith('/storage/content/', $image);
        Storage::disk('public')->assertExists(substr($image, strlen('/storage/')));
        $this->assertSame('/storage/content/staff.webp', $withPhoto->fresh()->image);

        $this->erzap('sinkronisasi_gambar', $meta)->assertOk()->assertJson(['olzap' => ['status' => '0']]);
    }
}

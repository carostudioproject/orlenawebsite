<?php

namespace Tests\Feature;

use App\Actions\Integrations\ErzapSync;
use App\Models\Category;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderWhatsApp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/** Hampers (customer chooses 1 item, its price added) and boxes (N items, priced per box), from catalog to Erzap. */
class HampersAndBoxTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    private Product $berry;

    private Product $matcha;

    private Product $hampers;

    private Product $box;

    private Product $choco;

    private Product $cheese;

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
        $brownies = Category::create(['name' => 'Brownies']);
        $hampers = Category::create(['name' => 'Hampers']);
        $mini = Category::create(['name' => 'Mini Size']);
        $product = fn (Category $category, string $sku, string $name, ?string $variant, ?int $price, bool $active = true, array $extra = []) => Product::create([
            'category_id' => $category->id, 'sku' => $sku, 'barcode' => 'BC-'.$sku, 'name' => $name, 'variant' => $variant, 'price' => $price, 'is_active' => $active, ...$extra,
        ]);
        $this->berry = $product($brownies, 'BR0005', 'Berry Cheese', 'Fullsize', 100000);
        $this->matcha = $product($brownies, 'BR0007', 'Matcha', 'Fullsize', 110000, false);
        $this->hampers = $product($hampers, 'HP0001', 'Hampers Galungan', 'Fullsize', 10000, true, ['content_type' => 'hampers', 'content_count' => 1, 'content_pricing' => 'added']);
        $this->hampers->contentOptions()->sync([$this->berry->id, $this->matcha->id]);
        // Mini flavours: Rp 0 and hidden from the menu, only sold inside the box.
        $this->choco = $product($mini, 'MN0001', 'Mini Choco', 'Mini', 0, false);
        $this->cheese = $product($mini, 'MN0002', 'Mini Cheese', 'Mini', 0, false);
        $this->box = $product($mini, 'MB0001', 'Mini Size Box', 'Box 2x2', 60000, true, ['content_type' => 'box', 'content_count' => 4, 'content_pricing' => 'included']);
        $this->box->contentOptions()->sync([$this->choco->id, $this->cheese->id]);
        $this->outlet = Outlet::create(['code' => 'T', 'name' => 'Test Outlet', 'address' => 'Jl', 'is_active' => true, 'is_delivery_hub' => true]);
        config(['services.erzap.enabled' => false]);
    }

    private function payload(array $items): array
    {
        $this->get('/order?outlet_id='.$this->outlet->id)->assertOk();

        return [
            'checkout_key' => session('checkout_key'), 'items' => $items, 'name' => 'Sinta', 'whatsapp' => '081234567890', 'outlet_id' => $this->outlet->id,
            'fulfillment_method' => 'pickup', 'requested_date' => '2030-01-02', 'requested_time' => '14:30', 'card_message' => 'Selamat Galungan',
        ];
    }

    public function test_the_order_form_lists_choosable_contents(): void
    {
        $this->get('/order')->assertInertia(fn (Assert $page) => $page
            ->where('products', function ($products) {
                $byId = collect($products)->keyBy('id');
                // Each size is its own card; inactive Mini flavours are not cards but are box contents.
                $this->assertFalse($byId->has($this->choco->id));
                $this->assertSame(['hampers', 1, [['id' => $this->berry->id, 'name' => 'Berry Cheese', 'variant' => 'Fullsize', 'image' => null, 'price' => 100000]]],
                    [$byId[$this->hampers->id]['content_type'], $byId[$this->hampers->id]['content_count'], $byId[$this->hampers->id]['contents']]);
                $this->assertSame(['box', 4, [0, 0]], [$byId[$this->box->id]['content_type'], $byId[$this->box->id]['content_count'], array_column($byId[$this->box->id]['contents'], 'price')]);
                $this->assertTrue($byId[$this->hampers->id]['is_hamper']);

                return true;
            }));

        // A hampers with no available contents cannot be ordered.
        $this->berry->update(['is_active' => false]);
        $this->get('/order')->assertInertia(fn (Assert $page) => $page->where('products', fn ($products) => ! collect($products)->contains('id', $this->hampers->id)));
    }

    public function test_hampers_and_box_contents_are_saved_as_lines_under_their_product(): void
    {
        $payload = $this->payload([
            ['product_id' => $this->hampers->id, 'quantity' => 2, 'quoted_price' => 110000, 'contents' => [$this->berry->id]],
            ['product_id' => $this->box->id, 'quantity' => 1, 'quoted_price' => 60000, 'contents' => [$this->choco->id, $this->choco->id, $this->choco->id, $this->cheese->id]],
            ['product_id' => $this->berry->id, 'quantity' => 1, 'quoted_price' => 100000],
        ]);
        $this->post('/order', $payload)->assertSessionHasNoErrors()->assertRedirect();

        $order = Order::with('items')->sole();
        $this->assertSame([380000, 'Selamat Galungan'], [$order->subtotal, $order->card_message]);
        $hampers = $order->items->firstWhere('product_id', $this->hampers->id);
        $this->assertSame([10000, 2, 20000, null], [$hampers->unit_price_snapshot, $hampers->quantity, $hampers->subtotal, $hampers->parent_item_id]);
        $inside = $order->items->where('parent_item_id', $hampers->id)->sole();
        $this->assertSame([$this->berry->id, 100000, 2, 200000], [$inside->product_id, $inside->unit_price_snapshot, $inside->quantity, $inside->subtotal]);
        $box = $order->items->firstWhere('product_id', $this->box->id);
        $flavours = $order->items->where('parent_item_id', $box->id)->mapWithKeys(fn ($item) => [$item->product_id => [$item->quantity, $item->subtotal]])->all();
        $this->assertSame([$this->choco->id => [3, 0], $this->cheese->id => [1, 0]], $flavours);

        $message = OrderWhatsApp::message($order);
        $this->assertStringContainsString('- 2x Hampers Galungan (Fullsize) = Rp 220.000', $message);
        $this->assertStringContainsString('   isi: 2x Berry Cheese (Fullsize)', $message);
        $this->get('/orders/'.$order->order_code)->assertInertia(fn (Assert $page) => $page->has('order.items', 6)->where('order.items.1.parent_item_id', $hampers->id));

        // Erzap gets the hampers, the box and every content with its own barcode; the same product at the same price is one line.
        $order->update(['payment_status' => 'paid']);
        $details = collect(app(ErzapSync::class)->pullCart($order->fresh(['items.product', 'outlet', 'customer', 'payments']))['shopping_cart_details'])->keyBy('barcode_produk');
        $this->assertSame(['10000.0', '2.0'], [$details['BC-HP0001']['harga_satuan'], $details['BC-HP0001']['jumlah']]);
        $this->assertSame(['100000.0', '3.0'], [$details['BC-BR0005']['harga_satuan'], $details['BC-BR0005']['jumlah']]);
        $this->assertSame(['0.0', '3.0'], [$details['BC-MN0001']['harga_satuan'], $details['BC-MN0001']['jumlah']]);
        $this->assertCount(5, $details);
    }

    public function test_contents_are_checked_on_the_server(): void
    {
        $line = fn (array $contents, int $quoted, ?int $product = null) => [['product_id' => $product ?? $this->hampers->id, 'quantity' => 1, 'quoted_price' => $quoted, 'contents' => $contents]];
        // Missing, inactive (not orderable alone, so not as added-price contents) or not allowed contents.
        $this->post('/order', $this->payload($line([], 10000)))->assertSessionHasErrors('items.0.contents');
        $this->post('/order', $this->payload($line([$this->matcha->id], 120000)))->assertSessionHasErrors('items.0.contents');
        $this->post('/order', $this->payload($line([$this->choco->id], 10000)))->assertSessionHasErrors('items.0.contents');
        // A box needs exactly its number of items.
        $this->post('/order', $this->payload($line([$this->choco->id, $this->cheese->id], 60000, $this->box->id)))->assertSessionHasErrors('items.0.contents');
        // The quoted unit price must include the contents.
        $this->post('/order', $this->payload($line([$this->berry->id], 10000)))->assertSessionHasErrors('pricing');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_staff_set_contents_in_the_product_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $base = ['name' => 'Hampers Nyepi', 'sku' => 'HP0009', 'category_id' => $this->hampers->category_id, 'variant' => 'Fullsize', 'price' => 15000, 'is_active' => true];
        $this->actingAs($admin)->post('/admin/products', [...$base, 'content_type' => 'hampers', 'content_count' => 5, 'content_pricing' => 'added', 'content_ids' => []])
            ->assertSessionHasErrors('content_ids');
        // Hampers and boxes cannot hold other hampers or boxes.
        $this->post('/admin/products', [...$base, 'content_type' => 'hampers', 'content_count' => 5, 'content_pricing' => 'added', 'content_ids' => [$this->box->id]])
            ->assertSessionHasErrors('content_ids.0');
        $this->post('/admin/products', [...$base, 'content_type' => 'hampers', 'content_count' => 5, 'content_pricing' => 'added', 'content_ids' => [$this->berry->id, $this->matcha->id]])
            ->assertSessionHasNoErrors()->assertRedirect('/admin/products?type=hampers');
        $created = Product::where('sku', 'HP0009')->sole();
        $this->assertSame(['hampers', 1, [$this->berry->id, $this->matcha->id]], [$created->content_type, $created->content_count, $created->contentOptions()->pluck('products.id')->sort()->values()->all()]);
        $this->get('/admin/products/'.$created->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('hamperMode', true)->has('record.content_ids', 2)
            ->where('contentCandidates', fn ($candidates) => ! collect($candidates)->contains('id', $this->box->id) && ! collect($candidates)->contains('id', $created->id)));

        // Back to a regular product clears the settings and the list.
        $this->put('/admin/products/'.$created->id, [...$base, 'content_type' => null, 'is_hamper' => true, 'hamper_contents' => '1x Berry'])->assertSessionHasNoErrors();
        $this->assertSame([null, null, 0], [$created->fresh()->content_type, $created->fresh()->content_count, $created->contentOptions()->count()]);
    }

    public function test_staff_enter_the_erzap_barcode_in_the_product_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $base = ['name' => 'Lotus Cheese', 'sku' => 'BR0099', 'category_id' => $this->berry->category_id, 'variant' => 'Fullsize', 'price' => 90000, 'is_active' => false];
        $this->actingAs($admin)->post('/admin/products', [...$base, 'barcode' => 'BC-BR0005'])->assertSessionHasErrors('barcode');
        $this->post('/admin/products', [...$base, 'barcode' => '1785 484'])->assertSessionHasErrors('barcode');
        $this->post('/admin/products', [...$base, 'barcode' => '1785484844999'])->assertSessionHasNoErrors();
        $product = Product::where('sku', 'BR0099')->sole();
        $this->assertSame('1785484844999', $product->barcode);
        $this->put('/admin/products/'.$product->id, [...$base, 'barcode' => ''])->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->barcode);
    }

    public function test_a_box_with_fixed_flavours_is_added_without_choosing(): void
    {
        $set = Product::create(['category_id' => $this->box->category_id, 'sku' => 'MB0002', 'barcode' => 'BC-MB0002', 'name' => 'Mini Box Classic', 'variant' => 'Box 2x2',
            'price' => 55000, 'is_active' => true, 'content_type' => 'set', 'content_count' => 4]);
        $set->contentOptions()->sync([$this->choco->id => ['quantity' => 3], $this->cheese->id => ['quantity' => 1]]);

        $this->get('/order')->assertInertia(fn (Assert $page) => $page->where('products', function ($products) use ($set) {
            $row = collect($products)->firstWhere('id', $set->id);
            $this->assertSame(['set', 4, []], [$row['content_type'], $row['content_count'], $row['contents']]);
            $this->assertSame([['Mini Cheese', 1], ['Mini Choco', 3]], array_map(fn ($item) => [$item['name'], $item['quantity']], $row['fixed_contents']));

            return true;
        }));
        // The customer cannot change what is inside.
        $this->post('/order', $this->payload([['product_id' => $set->id, 'quantity' => 2, 'quoted_price' => 55000, 'contents' => [$this->cheese->id]]]))
            ->assertSessionHasErrors('items.0.contents');
        $this->post('/order', $this->payload([['product_id' => $set->id, 'quantity' => 2, 'quoted_price' => 55000]]))->assertSessionHasNoErrors();

        $order = Order::with('items')->sole();
        $box = $order->items->firstWhere('product_id', $set->id);
        $this->assertSame([110000, 2], [$order->subtotal, $box->quantity]);
        $flavours = $order->items->where('parent_item_id', $box->id)->mapWithKeys(fn ($item) => [$item->product_id => [$item->quantity, $item->subtotal]])->all();
        $this->assertSame([$this->choco->id => [6, 0], $this->cheese->id => [2, 0]], $flavours);
    }

    public function test_staff_create_box_flavours_and_a_fixed_box(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $base = ['category_id' => $this->box->category_id, 'is_active' => false, 'price' => 0];
        $this->actingAs($admin)->post('/admin/products', [...$base, 'name' => 'Mini Almond', 'sku' => 'BR0054', 'variant' => 'Mini', 'content_type' => 'flavour'])->assertSessionHasNoErrors();
        $almond = Product::where('sku', 'BR0054')->sole();
        $this->assertSame(['flavour', null, null], [$almond->content_type, $almond->content_count, $almond->content_pricing]);

        // The form offers flavours for boxes and regular products for hampers.
        $this->get('/admin/products/create')->assertInertia(fn (Assert $page) => $page->where('contentCandidates', fn ($candidates) => collect($candidates)->firstWhere('id', $almond->id)['flavour'] === true
            && collect($candidates)->firstWhere('id', $this->berry->id)['flavour'] === false));
        // Hampers hold regular products only.
        $this->post('/admin/products', [...$base, 'name' => 'Hampers X', 'sku' => 'HP0100', 'variant' => 'Mini', 'content_type' => 'hampers', 'content_count' => 1, 'content_pricing' => 'added', 'content_ids' => [$almond->id]])
            ->assertSessionHasErrors('content_ids.0');

        // A fixed box: each id once per piece.
        $this->post('/admin/products', [...$base, 'name' => 'Mini Box Almond', 'sku' => 'MB0100', 'variant' => 'Box 2x2', 'price' => 55000, 'content_type' => 'set',
            'content_ids' => [$almond->id, $almond->id, $almond->id, $this->choco->id]])->assertSessionHasNoErrors();
        $set = Product::where('sku', 'MB0100')->sole();
        $this->assertSame(['set', 4, null], [$set->content_type, $set->content_count, $set->content_pricing]);
        $this->assertSame(collect([$almond->id => 3, $this->choco->id => 1])->sortKeysDesc()->all(), $set->contentOptions()->get()->mapWithKeys(fn ($option) => [$option->id => $option->pivot->quantity])->sortKeysDesc()->all());
        $this->get('/admin/products/'.$set->id.'/edit')->assertInertia(fn (Assert $page) => $page->where('record.content_ids', fn ($ids) => collect($ids)->sort()->values()->all() === collect([$almond->id, $almond->id, $almond->id, $this->choco->id])->sort()->values()->all()));
    }

    public function test_box_flavours_are_saved_at_rp_0_but_only_while_inactive(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $flavour = ['name' => 'Mini Size Almond', 'sku' => 'BR0054', 'barcode' => '1785484845001', 'category_id' => $this->choco->category_id, 'variant' => 'Mini', 'price' => 0];
        $this->actingAs($admin)->post('/admin/products', [...$flavour, 'is_active' => true])->assertSessionHasErrors('price');
        $this->post('/admin/products', [...$flavour, 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertSame([0, false], [Product::where('sku', 'BR0054')->sole()->price, Product::where('sku', 'BR0054')->sole()->is_active]);
    }

    public function test_the_excel_import_sets_types_and_contents_by_code(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $path = tempnam(sys_get_temp_dir(), 'erz').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ([
            ['Kode Produk', 'Barcode', 'Nama Produk', 'Kategori', 'Ukuran', 'Harga Jual', 'Tipe', 'Jumlah Isi', 'Isi Dari', 'Harga Isi'],
            ['MB0002', '777002', 'Mini Size Box', 'Mini Size', 'Box 3x3', 120000, 'Box', 9, 'MN0001, MN0002, MN0003', 'Termasuk'],
            ['MN0003', '777003', 'Mini Pandan', 'Mini Size', 'Mini', 0, 'Rasa box', '', '', ''],
            ['MB0003', '777004', 'Mini Box Pandan', 'Mini Size', 'Box 2x2', 55000, 'Box isi tetap', '', '3x MN0003, 1x MN0001', ''],
            ['HP0001', 'BC-HP0001', 'Hampers Galungan', 'Hampers', 'Fullsize', 10000, 'Hampers', '', 'BR0005, NOPE1', ''],
        ] as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
        $response = $this->actingAs($admin)->post('/admin/products/import', ['file' => new UploadedFile($path, 'produk.xlsx', null, null, true)])->assertRedirect();
        $token = str($response->headers->get('Location'))->after('token=')->toString();
        $this->get('/admin/products/import?token='.$token)->assertInertia(fn (Assert $page) => $page
            ->where('upload.summary', ['create' => 3, 'update' => 1, 'unchanged' => 0, 'skip' => 0])
            ->where('upload.rows', fn ($rows) => collect($rows)->firstWhere('sku', 'HP0001')['warning'] === 'Isi Dari: code not found: NOPE1.'
                && collect($rows)->firstWhere('sku', 'HP0001')['changes']['contents'] === ['BR0005, BR0007', 'BR0005, NOPE1']));
        $this->post('/admin/products/import/apply', ['token' => $token])->assertRedirect('/admin/products');

        $box = Product::where('sku', 'MB0002')->sole();
        $this->assertSame(['box', 9, 'included', false], [$box->content_type, $box->content_count, $box->content_pricing, $box->is_active]);
        // Codes may point to products created by the same file.
        $this->assertSame(['MN0001', 'MN0002', 'MN0003'], $box->contentOptions()->orderBy('sku')->pluck('sku')->all());
        $this->assertSame(['BR0005'], $this->hampers->contentOptions()->pluck('sku')->all());
        $this->assertSame('flavour', Product::where('sku', 'MN0003')->sole()->content_type);
        // "3x MN0003, 1x MN0001": a fixed box keeps how many of each flavour.
        $fixed = Product::where('sku', 'MB0003')->sole();
        $this->assertSame(['set', 4, ['MN0001' => 1, 'MN0003' => 3]], [$fixed->content_type, $fixed->content_count, $fixed->contentOptions()->orderBy('sku')->get()->mapWithKeys(fn ($option) => [$option->sku => $option->pivot->quantity])->all()]);
    }
}

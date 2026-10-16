<?php

namespace Tests\Feature;

use App\Actions\Integrations\ErzapSync;
use App\Models\Category;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Reports\ProductionRecap;
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
        $this->outlet = Outlet::create(['code' => 'T', 'name' => 'Test Outlet', 'address' => 'Jl', 'is_active' => true, 'delivers' => true]);
        config(['services.erzap.enabled' => false]);
    }

    private function payload(array $items): array
    {
        $this->get('/order?outlet_id='.$this->outlet->id)->assertOk();

        return [
            'checkout_key' => session('checkout_key'), 'items' => $items, 'name' => 'Sinta', 'whatsapp' => '081234567890', 'outlet_id' => $this->outlet->id,
            'fulfillment_method' => 'pickup', 'requested_date' => '2030-01-02', 'requested_time' => '14:00', 'card_message' => 'Selamat Galungan',
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

    public function test_highlighted_products_come_first_on_the_order_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $form = fn (Product $product, array $changes) => [
            ...$product->only('name', 'sku', 'category_id', 'variant', 'price', 'is_active', 'content_type', 'content_count', 'content_pricing'),
            'barcode' => $product->barcode, 'content_ids' => $product->contentOptions()->pluck('products.id')->all(), ...$changes,
        ];
        $this->actingAs($admin)->put('/admin/products/'.$this->box->id, $form($this->box, ['is_featured' => true, 'featured_label' => str_repeat('x', 31)]))->assertSessionHasErrors('featured_label');
        $this->put('/admin/products/'.$this->box->id, $form($this->box, ['is_featured' => true, 'featured_label' => 'Spesial Natal', 'featured_position' => 2]))->assertSessionHasNoErrors();
        $this->put('/admin/products/'.$this->hampers->id, $form($this->hampers, ['is_featured' => true, 'featured_position' => 1]))->assertSessionHasNoErrors();
        $this->get('/admin/products?status=featured')->assertInertia(fn (Assert $page) => $page->has('records.data', 2));
        auth()->logout();

        // Highlighted first, in their own order; the badge text goes with them, other products keep theirs.
        $this->get('/order?outlet_id='.$this->outlet->id)->assertInertia(fn (Assert $page) => $page
            ->where('products.0.id', $this->hampers->id)->where('products.0.featured_label', null)
            ->where('products.1.id', $this->box->id)->where('products.1.featured_label', 'Spesial Natal')
            ->where('products.2.featured', false));
    }

    public function test_category_extras_are_offered_in_the_popup_and_a_card_keeps_its_message(): void
    {
        $packaging = Category::create(['name' => 'Packaging']);
        $card = Product::create(['category_id' => $packaging->id, 'sku' => 'P0028', 'barcode' => 'BC-P0028', 'name' => 'Card', 'price' => 5000, 'is_active' => true,
            'input_label' => 'Tulisan kartu ucapan', 'input_max' => 40]);
        $candle = Product::create(['category_id' => $packaging->id, 'sku' => 'P0087', 'barcode' => 'BC-P0087', 'name' => 'Lilin', 'price' => 5000, 'is_active' => true]);
        $bag = Product::create(['category_id' => $packaging->id, 'sku' => 'P0003', 'barcode' => 'BC-P0003', 'name' => 'Paper Bag Kecil', 'price' => 4000, 'is_active' => false]);
        $brownies = $this->berry->category;

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/categories/'.$brownies->id.'/edit')->assertInertia(fn (Assert $page) => $page
            ->where('addonCandidates.0.category', 'Packaging')->where('record.addon_ids', []));
        // Only regular products can be extras.
        $this->put('/admin/categories/'.$brownies->id, ['name' => 'Brownies', 'is_active' => true, 'addon_ids' => [$this->box->id]])->assertSessionHasErrors('addon_ids.0');
        $this->put('/admin/categories/'.$brownies->id, ['name' => 'Brownies', 'is_active' => true, 'addon_ids' => [$candle->id, $card->id, $bag->id]])->assertSessionHasNoErrors();
        $this->assertSame([$candle->id, $card->id, $bag->id], $brownies->addons()->pluck('products.id')->all());
        auth()->logout();

        // The inactive paper bag is not offered; the card asks for its text on its cart line.
        $this->get('/order?outlet_id='.$this->outlet->id)->assertInertia(fn (Assert $page) => $page
            ->where('categories', fn ($categories) => collect($categories)->firstWhere('id', $brownies->id)['addon_ids'] === [$candle->id, $card->id])
            ->where('products', fn ($products) => collect($products)->firstWhere('id', $card->id)['input'] === ['label' => 'Tulisan kartu ucapan', 'placeholder' => null, 'required' => false, 'max' => 40]
                && collect($products)->firstWhere('id', $candle->id)['input'] === null));
        $cards = fn (array $texts, int $quantity) => ['product_id' => $card->id, 'quantity' => $quantity, 'quoted_price' => 5000, 'input_texts' => $texts];
        $berry = ['product_id' => $this->berry->id, 'quantity' => 1, 'quoted_price' => 100000];
        $this->post('/order', $this->payload([$berry, $cards(['ok', str_repeat('a', 41)], 2)]))->assertSessionHasErrors('items.1.input_texts.1');
        $this->post('/order', $this->payload([$berry, $cards(['a', 'b', 'c'], 2)]))->assertSessionHasErrors('items.1.input_texts');
        // Optional: an unwritten card is fine. Required: every card needs its own text.
        $card->update(['input_required' => true]);
        $this->post('/order', $this->payload([$berry, $cards(['Halo', '   '], 2)]))->assertSessionHasErrors('items.1.input_texts.1');
        $this->post('/order', $this->payload([$berry, $cards(['Halo'], 3)]))->assertSessionHasErrors('items.1.input_texts.1');
        // Three cards on one line, each with its own greeting; the order-level card is for hampers only, and the candle ignores any text.
        $this->post('/order', [...$this->payload([$berry, $cards(['  Selamat   ulang tahun ', 'Get well soon', 'Congrats!'], 3), ['product_id' => $candle->id, 'quantity' => 1, 'quoted_price' => 5000, 'input_texts' => ['x']]]), 'card_message' => 'Halo'])
            ->assertSessionHasNoErrors();
        $order = Order::sole();
        $this->assertSame([120000, null], [$order->subtotal, $order->card_message]);
        $this->assertSame([[null, null], ['Tulisan kartu ucapan', ['Selamat ulang tahun', 'Get well soon', 'Congrats!']], [null, null]],
            $order->items()->orderBy('id')->get()->map(fn ($item) => [$item->input_label_snapshot, $item->input_texts])->all());
        $this->assertStringContainsString("   Tulisan kartu ucapan 1: Selamat ulang tahun\n   Tulisan kartu ucapan 2: Get well soon", OrderWhatsApp::message($order));

        // Rekap CK lists each text under its line and in the WhatsApp text.
        $order->update(['order_status' => 'confirmed', 'payment_status' => 'paid']);
        $recap = app(ProductionRecap::class);
        $data = $recap->build($order->requested_date->toDateString());
        $this->assertSame(['Tulisan kartu ucapan 1: Selamat ulang tahun', 'Tulisan kartu ucapan 2: Get well soon', 'Tulisan kartu ucapan 3: Congrats!'], $data['orders'][0]['orders'][0]['items'][1]['input']);
        $this->assertStringContainsString('Tulisan kartu ucapan 3: Congrats!', $recap->whatsappText($data));
    }

    public function test_staff_set_the_customer_text_option(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $base = ['name' => 'Tart Ulang Tahun', 'sku' => 'TT0001', 'category_id' => $this->berry->category_id, 'price' => 250000, 'is_active' => true];
        $this->actingAs($admin)->post('/admin/products', [...$base, 'input_label' => 'Tulisan di kue', 'input_max' => 5])->assertSessionHasErrors('input_max');
        $this->post('/admin/products', [...$base, 'input_label' => 'Tulisan di kue', 'input_placeholder' => 'Happy birthday!', 'input_required' => true, 'input_max' => 40])->assertSessionHasNoErrors();
        $this->assertSame(['label' => 'Tulisan di kue', 'placeholder' => 'Happy birthday!', 'required' => true, 'max' => 40], Product::where('sku', 'TT0001')->sole()->customerInput());
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

    public function test_the_kitchen_recap_counts_paid_orders_with_their_contents(): void
    {
        $lines = [
            ['product_id' => $this->hampers->id, 'quantity' => 2, 'quoted_price' => 110000, 'contents' => [$this->berry->id]],
            ['product_id' => $this->box->id, 'quantity' => 1, 'quoted_price' => 60000, 'contents' => [$this->choco->id, $this->choco->id, $this->choco->id, $this->cheese->id]],
            ['product_id' => $this->berry->id, 'quantity' => 1, 'quoted_price' => 100000],
        ];
        $this->post('/order', $this->payload($lines))->assertSessionHasNoErrors();
        $this->post('/order', $this->payload([['product_id' => $this->berry->id, 'quantity' => 5, 'quoted_price' => 100000]]))->assertSessionHasNoErrors();
        $this->post('/order', $this->payload([['product_id' => $this->berry->id, 'quantity' => 7, 'quoted_price' => 100000]]))->assertSessionHasNoErrors();
        [$paid, $unpaid, $cancelled] = Order::orderBy('id')->get()->all();
        $paid->update(['payment_status' => 'paid', 'order_status' => 'confirmed']);
        $unpaid->update(['order_status' => 'confirmed']);
        $cancelled->update(['payment_status' => 'paid', 'order_status' => 'cancelled']);

        $recap = app(ProductionRecap::class)->build('2030-01-02');
        // Contents count as products to bake (2 hampers x 1 Berry + 1 Berry); hampers and boxes are packaging.
        $this->assertFalse($recap['final']);
        $this->assertSame(['orders' => 1, 'pickup' => 1, 'delivery' => 0, 'pieces' => 7], $recap['summary']);
        $this->assertSame([['Berry Cheese', 3], ['Mini Cheese', 1], ['Mini Choco', 3]], array_map(fn ($row) => [$row['name'], $row['quantity']], $recap['products']));
        $this->assertSame(['Test Outlet' => 3], $recap['products'][0]['by_outlet']);
        $this->assertSame([['Hampers Galungan', 2], ['Mini Size Box', 1]], array_map(fn ($row) => [$row['name'], $row['quantity']], $recap['packages']));
        $this->assertSame(1, $recap['unpaid']);
        $this->assertSame(['2× Berry Cheese (Fullsize)'], $recap['orders'][0]['orders'][0]['items'][0]['contents']);

        $text = app(ProductionRecap::class)->whatsappText($recap);
        $this->assertStringContainsString("*Total produksi*\n- 3x Berry Cheese (Fullsize)", $text);
        $this->assertStringContainsString('SEMENTARA', $text);

        // Final after the D-1 cutoff; the CK number from the schedule settings goes into the wa.me link.
        $this->travelTo(CarbonImmutable::parse('2030-01-01 19:00:01', 'Asia/Makassar'));
        Setting::put(['ck.whatsapp' => '6281234567890']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/production?date=2030-01-02')->assertInertia(fn (Assert $page) => $page->component('Admin/Production')
            ->where('recap.final', true)->where('recap.summary.pieces', 7)->where('whatsappUrl', fn ($url) => str_starts_with($url, 'https://wa.me/6281234567890?text=')));
        $this->get('/admin/production/pdf?date=2030-01-02')->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="rekap-ck-orlena-2030-01-02.pdf"');
        $this->get('/admin/production?date=02-01-2030')->assertSessionHasErrors('date');

        $this->actingAs(User::factory()->create(['role' => 'content_editor']))->get('/admin/production')->assertForbidden();
    }

    public function test_the_kitchen_whatsapp_number_is_set_on_the_schedule_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $base = ['cutoff_time' => '19:00', 'payment_close_time' => '21:00', 'max_days' => 30, 'closed_weekdays' => [], 'delivery_tolerance' => 60];
        $this->put('/admin/schedule', [...$base, 'ck_whatsapp' => '0812 3456'])->assertSessionHasErrors('ck_whatsapp');
        $this->put('/admin/schedule', [...$base, 'ck_whatsapp' => '6281234567890'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('6281234567890', Setting::get('ck.whatsapp'));
        $this->get('/admin/schedule')->assertInertia(fn (Assert $page) => $page->where('settings.ck_whatsapp', '6281234567890')->where('settings.delivery_tolerance', 60));
        // Clearing a value (no CK number) removes it instead of failing on the NOT NULL column.
        $this->put('/admin/schedule', [...$base, 'ck_whatsapp' => ''])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(Setting::get('ck.whatsapp'));
    }
}

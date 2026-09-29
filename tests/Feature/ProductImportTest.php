<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Erzap\ProductImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private Product $berry;

    private Product $matcha;

    private User $admin;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $category = Category::create(['name' => 'Fullsize Brownies', 'is_active' => true]);
        // Seeded product without barcode (matched by name on the first import) and one already linked by barcode.
        $this->berry = Product::create([
            'category_id' => $category->id, 'variant' => 'Fullsize', 'sku' => 'ORL-FB-001', 'name' => 'Berry Cheese', 'price' => 80000, 'is_active' => true,
            'image' => '/storage/products/berry.webp', 'description' => 'Our description',
        ]);
        $this->matcha = Product::create([
            'category_id' => $category->id, 'variant' => 'Fullsize', 'sku' => 'ORL-FB-002', 'name' => 'Matcha', 'price' => 85000, 'is_active' => false,
            'barcode' => '8990002', 'image' => '/storage/products/matcha.webp', 'is_hamper' => true, 'hamper_contents' => "Matcha\nCard",
        ]);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'erz').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'produk-erzap.xlsx', null, null, true);
    }

    private function upload(UploadedFile $file): string
    {
        $response = $this->actingAs($this->admin)->post('/admin/products/import', ['file' => $file])->assertRedirect();

        return str($response->headers->get('Location'))->after('token=')->toString();
    }

    public function test_import_previews_then_updates_only_erzap_fields_and_keeps_photos(): void
    {
        $token = $this->upload($this->xlsx([
            ['Kode Produk', 'Barcode', 'Nama Produk', 'Kategori', 'Ukuran', 'Harga Jual', 'Stok'],
            ['BR-01', '8990001', 'Berry Cheese', 'Brownies', 'Fullsize', 82000, 7],
            ['MT-01', '8990002', 'Matcha Latte', 'Brownies', 'Fullsize', 85000, 0],
            ['SC-01', '8990003', 'Sauce Coklat', 'Sauce', null, 'Rp 25.000', 3],
            ['', '', 'No code', 'Sauce', null, 1000, 0],
            ['SC-02', '8990003', 'Duplicate barcode', 'Sauce', null, 1000, 0],
        ]));

        // Preview only: nothing saved yet.
        $this->get('/admin/products/import?token='.$token)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Catalog/Import')
            ->where('upload.summary', ['create' => 1, 'update' => 2, 'unchanged' => 0, 'skip' => 2])
            ->where('upload.mapping', ['barcode' => 1, 'sku' => 0, 'name' => 2, 'price' => 5, 'category' => 3, 'variant' => 4, 'hamper' => null, 'hamper_contents' => null])
            ->where('upload.errors', [])->where('upload.update_names', false)
            // Names of existing products stay unless staff opt in.
            ->where('upload.rows.1.current', 'Matcha (Fullsize)')->missing('upload.rows.1.changes.name'));
        $this->assertNull($this->berry->fresh()->barcode);
        $this->assertSame(2, Product::count());
        $this->post('/admin/products/import/mapping', ['token' => $token, 'update_names' => true,
            'mapping' => ['barcode' => 1, 'sku' => 0, 'name' => 2, 'price' => 5, 'category' => 3, 'variant' => 4]])->assertRedirect();
        $this->get('/admin/products/import?token='.$token)->assertInertia(fn (Assert $page) => $page
            ->where('upload.update_names', true)->where('upload.rows.1.changes.name', ['Matcha', 'Matcha Latte']));

        $this->post('/admin/products/import/apply', ['token' => $token])->assertRedirect('/admin/products')->assertSessionHas('success');

        $berry = $this->berry->fresh();
        $this->assertSame(['8990001', 'BR-01', 82000, 'Berry Cheese'], [$berry->barcode, $berry->sku, $berry->price, $berry->name]);
        $this->assertSame(['/storage/products/berry.webp', 'Our description', true, 'Fullsize Brownies'], [$berry->image, $berry->description, $berry->is_active, $berry->category->name]);

        $matcha = $this->matcha->fresh();
        $this->assertSame(['Matcha Latte', 'MT-01', '/storage/products/matcha.webp', false, true, "Matcha\nCard"], [$matcha->name, $matcha->sku, $matcha->image, $matcha->is_active, $matcha->is_hamper, $matcha->hamper_contents]);

        $sauce = Product::where('barcode', '8990003')->sole();
        $this->assertSame(['Sauce Coklat', 25000, false, 'Sauce', 'SC-01', null], [$sauce->name, $sauce->price, $sauce->is_active, $sauce->category->name, $sauce->sku, $sauce->image]);
        $this->assertSame(3, Product::count());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'catalog.erzap_import')->exists());

        // Re-import: a new photo uploaded on the website stays; only the price changes.
        $sauce->update(['image' => '/storage/products/sauce.webp', 'is_active' => true]);
        $token = $this->upload($this->xlsx([
            ['Kode Produk', 'Barcode', 'Nama Produk', 'Kategori', 'Ukuran', 'Harga Jual'],
            ['BR-01', '8990001', 'Berry Cheese', 'Brownies', 'Fullsize', 82000],
            ['SC-01', '8990003', 'Sauce Coklat', 'Other', null, 27000],
        ]));
        $this->get('/admin/products/import?token='.$token)->assertInertia(fn (Assert $page) => $page->where('upload.summary', ['create' => 0, 'update' => 1, 'unchanged' => 1, 'skip' => 0]));
        $this->post('/admin/products/import/apply', ['token' => $token])->assertRedirect('/admin/products');
        $sauce = $sauce->fresh();
        $this->assertSame([27000, '/storage/products/sauce.webp', true, 'Sauce'], [$sauce->price, $sauce->image, $sauce->is_active, $sauce->category->name]);
        // The upload token is single use.
        $this->post('/admin/products/import/apply', ['token' => $token])->assertStatus(410);
    }

    public function test_csv_with_semicolons_and_manual_column_mapping(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'erz').'.csv';
        file_put_contents($path, "Item;Kode Item;Harga Jual Outlet\nBerry Cheese;BR-01;\"80.000\"\n");
        $token = $this->upload(new UploadedFile($path, 'export.csv', 'text/csv', null, true));

        // "Item" is not a known header: the name column must be chosen before applying.
        $this->get('/admin/products/import?token='.$token)->assertInertia(fn (Assert $page) => $page
            ->where('upload.mapping.name', null)->where('upload.mapping.sku', 1)->where('upload.mapping.price', 2)->has('upload.errors', 1));
        $this->post('/admin/products/import/apply', ['token' => $token])->assertSessionHasErrors('mapping');
        $this->post('/admin/products/import/mapping', ['token' => $token, 'mapping' => ['name' => 1, 'sku' => 1]])->assertSessionHasErrors('mapping');
        $this->post('/admin/products/import/mapping', ['token' => $token, 'mapping' => ['name' => 0, 'sku' => 1, 'price' => 2]])->assertRedirect();

        // Matched by name + no variant fails (seed has Fullsize), so it becomes a new inactive product without barcode.
        $this->get('/admin/products/import?token='.$token)->assertInertia(fn (Assert $page) => $page
            ->where('upload.summary.create', 1)->where('upload.rows.0.warning', 'No barcode: orders with this product cannot be sent to Erzap.'));
    }

    public function test_only_catalog_managers_can_import_and_bad_files_are_rejected(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff)->get('/admin/products/import')->assertForbidden();
        $this->actingAs($this->admin)->post('/admin/products/import', ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('file');
        $this->actingAs($this->admin)->post('/admin/products/import', ['file' => $this->xlsx([['Nama', 'Barcode']])])->assertSessionHasErrors(['file' => 'The file has no product rows.']);
        // Someone else's token is not visible.
        $token = $this->upload($this->xlsx([['Nama', 'Barcode'], ['Berry', '1']]));
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($other)->get('/admin/products/import?token='.$token)->assertInertia(fn (Assert $page) => $page->where('upload', null));
    }

    public function test_excel_template_lists_the_catalog_and_imports_back_cleanly(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']))->get('/admin/products/import/template')->assertForbidden();
        $response = $this->actingAs($this->admin)->get('/admin/products/import/template')->assertOk()
            ->assertDownload('format-import-produk-orlena.xlsx');
        $path = $response->baseResponse->getFile()->getPathname();

        [$headers, $rows] = (new ProductImport)->read($path, 'xlsx');
        $this->assertSame(['Kode Produk', 'Barcode', 'Nama Produk', 'Kategori', 'Ukuran', 'Harga Jual'], $headers);
        $this->assertSame(['barcode' => 1, 'sku' => 0, 'name' => 2, 'price' => 5, 'category' => 3, 'variant' => 4, 'hamper' => null, 'hamper_contents' => null], (new ProductImport)->detect($headers));
        $this->assertSame(['ORL-FB-001', '', 'Berry Cheese', 'Fullsize Brownies', 'Fullsize', 80000], $rows[0]);
        $this->assertSame('8990002', $rows[1][1]);

        // Staff add the Erzap barcode and upload the same file: only that product changes.
        $upload = $this->xlsx([$headers, ['ORL-FB-001', '8990001', 'Berry Cheese', 'Fullsize Brownies', 'Fullsize', 80000], $rows[1]]);
        $token = $this->upload($upload);
        $this->get('/admin/products/import?token='.$token)->assertInertia(fn (Assert $page) => $page->where('upload.summary', ['create' => 0, 'update' => 1, 'unchanged' => 1, 'skip' => 0]));
    }

    public function test_hampers_file_creates_hamper_products_with_contents(): void
    {
        $token = $this->upload($this->xlsx([
            ['Kode Produk', 'Barcode', 'Nama Produk', 'Kategori', 'Ukuran', 'Harga Jual', 'Hampers', 'Isi Hampers'],
            ['BR0031', '1785484844702', 'Gebogan BC Hexagon', 'Hampers', 'Hexagon', 350000, 'Ya', 'Brownies Blueberry Cheese; Box hexagon'],
            ['BR0032', '1785484844708', 'Gebogan BM Hexagon', 'Hampers', 'Hexagon', '', 'Ya', ''],
        ]));
        $this->post('/admin/products/import/apply', ['token' => $token])->assertRedirect('/admin/products');

        $bc = Product::where('barcode', '1785484844702')->sole();
        $this->assertSame([true, "Brownies Blueberry Cheese\nBox hexagon", false, 'Hampers'], [$bc->is_hamper, $bc->hamper_contents, $bc->is_active, $bc->category->name]);
        $this->assertTrue(Product::where('barcode', '1785484844708')->sole()->is_hamper);
    }

    public function test_price_parsing(): void
    {
        $import = new ProductImport;
        foreach ([[60000, 60000], [60000.0, 60000], ['60000', 60000], ['Rp 60.000', 60000], ['60.000,00', 60000], ['60,000', 60000], ['60000.5', 60001], ['', null], ['abc', null], [0, null]] as [$input, $expected]) {
            $this->assertSame($expected, $import->price($input), var_export($input, true));
        }
    }
}

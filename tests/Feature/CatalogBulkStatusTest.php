<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogBulkStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    private function product(string $name, ?int $price, bool $hamper = false, bool $active = false): Product
    {
        $category = Category::firstOrCreate(['name' => 'Brownies']);

        return Product::create(['category_id' => $category->id, 'sku' => 'SKU-'.$name, 'name' => $name, 'price' => $price, 'is_active' => $active, 'is_hamper' => $hamper]);
    }

    public function test_selected_products_are_activated_except_those_without_a_price(): void
    {
        $berry = $this->product('Berry', 80000);
        $matcha = $this->product('Matcha', null);
        $other = $this->product('Other', 70000);
        $this->actingAs(User::factory()->create(['role' => 'staff']))->post('/admin/products/bulk-status', ['is_active' => true, 'ids' => [$berry->id]])->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/admin/products/bulk-status', ['is_active' => true, 'ids' => []])->assertSessionHasErrors('ids');
        $this->post('/admin/products/bulk-status', ['is_active' => true, 'ids' => [$berry->id, $matcha->id]])
            ->assertSessionHas('success', '1 product activated. 1 stayed inactive because they have no base price yet.');
        $this->assertSame([true, false, false], [$berry->fresh()->is_active, $matcha->fresh()->is_active, $other->fresh()->is_active]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'catalog.activated')->count());

        $this->post('/admin/products/bulk-status', ['is_active' => false, 'ids' => [$berry->id, $matcha->id]])->assertSessionHas('success', '1 product deactivated.');
        $this->assertFalse($berry->fresh()->is_active);

        // Only unpriced products: an error that explains why, and the list says up front which ones would be skipped.
        $this->post('/admin/products/bulk-status', ['is_active' => true, 'ids' => [$matcha->id]])
            ->assertSessionHasErrors(['is_active' => 'No products were activated: 1 has no base price yet. Set the price (Edit, or import the Excel file with Harga Jual filled), then activate again.']);
        $this->get('/admin/products')->assertInertia(fn ($page) => $page->where('unpriced', [$matcha->id]));
    }

    public function test_order_form_category_order_is_set_on_the_category(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $brownies = $this->product('Berry', 80000, active: true)->category;
        $sauce = Category::create(['name' => 'Sauce']);
        Product::create(['category_id' => $sauce->id, 'sku' => 'S1', 'name' => 'Nutella', 'price' => 8000, 'is_active' => true]);
        $this->get('/order')->assertInertia(fn ($page) => $page->where('categories.0.name', 'Brownies'));

        $this->put('/admin/categories/'.$sauce->id, ['name' => 'Sauce', 'is_active' => true, 'order_position' => 1])->assertSessionHasNoErrors();
        $this->put('/admin/categories/'.$brownies->id, ['name' => 'Brownies', 'is_active' => true, 'order_position' => 2])->assertSessionHasNoErrors();
        $this->get('/order')->assertInertia(fn ($page) => $page->where('categories.0.name', 'Sauce')->where('categories.1.name', 'Brownies'));
        // Website content ordering (Baked Goods) stays separate.
        $this->assertSame(0, $sauce->fresh()->position);
    }

    public function test_activate_all_follows_the_list_filters(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $regular = $this->product('Regular', 80000);
        $gebogan = $this->product('Gebogan', 350000, hamper: true);
        $canapes = $this->product('Canapes', 250000, hamper: true);

        // "Activate all" on the Hampers list only touches hampers.
        $this->post('/admin/products/bulk-status', ['is_active' => true, 'all' => true, 'filters' => ['type' => 'hampers']])->assertSessionHas('success', '2 products activated.');
        $this->assertSame([false, true, true], [$regular->fresh()->is_active, $gebogan->fresh()->is_active, $canapes->fresh()->is_active]);

        $this->post('/admin/products/bulk-status', ['is_active' => false, 'all' => true, 'filters' => ['search' => 'geb']])->assertSessionHas('success', '1 product deactivated.');
        $this->assertSame([false, true], [$gebogan->fresh()->is_active, $canapes->fresh()->is_active]);

        $this->post('/admin/categories/bulk-status', ['is_active' => false, 'all' => true])->assertSessionHas('success', '1 category deactivated.');
    }
}

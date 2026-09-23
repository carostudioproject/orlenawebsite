<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only the isolated orlena_test database is allowed.');
        }
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Role::Admin, 'is_active' => true]);
    }

    private function productData(array $overrides = []): array
    {
        return array_replace(['name' => 'Test brownie', 'sku' => 'TEST-001', 'category_id' => Category::create(['name' => 'Test category'])->id, 'price' => 55000, 'is_active' => true, 'outlet_prices' => []], $overrides);
    }

    public function test_guests_cannot_access_or_mutate_admin_resources(): void
    {
        foreach (['/admin', '/admin/products', '/admin/outlets', '/admin/categories', '/admin/users'] as $url) {
            $this->get($url)->assertRedirect('/admin/login');
        }
        $this->post('/admin/products', [])->assertRedirect('/admin/login');
        $this->get('/register')->assertNotFound();
    }

    public function test_login_logout_disabled_account_and_attempt_limits(): void
    {
        $user = User::factory()->create(['username' => 'staff.one', 'email' => 'staff@example.test', 'password' => 'TestPassword123', 'is_active' => true]);
        // Login uses the username (case-insensitive); the email is no longer a login name.
        $this->post('/admin/login', ['username' => 'staff@example.test', 'password' => 'TestPassword123'])->assertSessionHasErrors('username');
        $this->post('/admin/login', ['username' => ' STAFF.one ', 'password' => 'TestPassword123'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
        $user->forceFill(['is_active' => false])->save();
        $this->post('/admin/login', ['username' => $user->username, 'password' => 'TestPassword123'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $user->forceFill(['is_active' => true])->save();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['username' => $user->username, 'password' => 'wrong'])->assertSessionHasErrors('username');
        }
        $this->post('/admin/login', ['username' => $user->username, 'password' => 'TestPassword123'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_staff_can_read_but_cannot_manage_catalog_or_accounts(): void
    {
        $staff = User::factory()->create();
        $this->actingAs($staff)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')->where('auth.can_manage', false));
        foreach (['products', 'outlets', 'categories'] as $resource) {
            $this->get('/admin/'.$resource)->assertOk();
            $this->get('/admin/'.$resource.'/create')->assertForbidden();
            $this->post('/admin/'.$resource, [])->assertForbidden();
            $this->put('/admin/'.$resource.'/1', [])->assertForbidden();
        }
        $this->get('/admin/users')->assertForbidden();
        $this->post('/admin/users', [])->assertForbidden();
    }

    public function test_disabling_an_account_blocks_its_existing_session(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user)->get('/admin')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_admin_can_create_update_filter_and_audit_products_with_outlet_prices(): void
    {
        $admin = $this->admin();
        $outlet = Outlet::create(['code' => 'TEST', 'name' => 'Test outlet', 'address' => 'Test address']);
        $data = $this->productData(['outlet_prices' => [['outlet_id' => $outlet->id, 'price' => 60000]]]);
        $this->actingAs($admin)->post('/admin/products', $data)->assertRedirect('/admin/products');
        $product = Product::firstOrFail();
        $this->assertSame(60000, $product->priceAt($outlet));
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.created', 'actor_id' => $admin->id]);
        $this->get('/admin/products/'.$product->id.'/edit')->assertOk();
        $this->put('/admin/products/'.$product->id, [...$data, 'price' => 57000, 'is_active' => false, 'outlet_prices' => []])->assertRedirect('/admin/products');
        $this->assertSame(57000, $product->fresh()->priceAt($outlet));
        $this->assertDatabaseCount('outlet_prices', 0);
        $this->get('/admin/products?status=active')->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
        $this->get('/admin/products?search=brownie')->assertInertia(fn (Assert $page) => $page->has('records.data', 1));
    }

    public function test_active_products_require_real_integer_price_and_valid_category(): void
    {
        $this->actingAs($this->admin());
        $data = $this->productData();
        foreach ([null, 0, -1, 2.5] as $price) {
            $this->post('/admin/products', [...$data, 'price' => $price])->assertSessionHasErrors('price');
        }
        $this->post('/admin/products', [...$data, 'category_id' => 99999])->assertSessionHasErrors('category_id');
        $this->post('/admin/products', [...$data, 'outlet_prices' => [['outlet_id' => 99999, 'price' => 10000]]])->assertSessionHasErrors('outlet_prices.0.outlet_id');
        $this->post('/admin/products', [...$data, 'price' => null, 'is_active' => false])->assertRedirect('/admin/products');
        $this->assertDatabaseCount('products', 1);
    }

    public function test_admin_can_manage_outlets_categories_and_seed_is_non_destructive(): void
    {
        $this->actingAs($this->admin());
        $this->post('/admin/categories', ['name' => 'New category'])->assertRedirect('/admin/categories');
        $this->post('/admin/outlets', ['name' => 'New outlet', 'code' => 'NEW', 'address' => 'Address', 'maps_url' => 'javascript:alert(1)', 'is_active' => false])->assertSessionHasErrors('maps_url');
        $this->seed(CatalogSeeder::class);
        $outlet = Outlet::firstOrFail();
        $this->put('/admin/outlets/'.$outlet->id, ['name' => 'Updated outlet', 'code' => $outlet->code, 'address' => 'New address', 'maps_url' => null, 'is_active' => true])->assertRedirect('/admin/outlets');
        $this->seed(CatalogSeeder::class);
        $this->assertDatabaseCount('outlets', 5);
        $this->assertDatabaseHas('outlets', ['id' => $outlet->id, 'name' => 'Updated outlet']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_account_management_preserves_last_admin_and_keeps_secrets_out_of_audit(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $data = ['name' => 'Staff', 'username' => 'new.staff', 'email' => 'new@example.test', 'role' => 'staff', 'is_active' => true, 'password' => 'LongPassword123', 'password_confirmation' => 'LongPassword123'];
        $this->post('/admin/users', $data)->assertRedirect('/admin/users');
        $staff = User::where('email', $data['email'])->firstOrFail();
        $this->assertTrue(Hash::check($data['password'], $staff->password));
        $this->put('/admin/users/'.$admin->id, ['name' => $admin->name, 'username' => $admin->username, 'email' => $admin->email, 'role' => 'staff', 'is_active' => true])->assertSessionHasErrors('role');
        $this->put('/admin/users/'.$admin->id, ['name' => $admin->name, 'username' => $admin->username, 'email' => $admin->email, 'role' => 'admin', 'is_active' => false])->assertSessionHasErrors('role');
        $this->get('/admin/users/'.$staff->id.'/edit')->assertOk();
        $this->assertStringNotContainsString('LongPassword123', json_encode(DB::table('audit_logs')->get()));
        $this->assertStringNotContainsString($staff->password, json_encode(DB::table('audit_logs')->get()));
    }

    public function test_admin_pages_are_never_indexed_or_tracked(): void
    {
        config(['site.indexable' => true, 'site.meta_pixel_enabled' => true]);
        $this->get('/admin/login')->assertOk()->assertSee('noindex,nofollow', false)->assertDontSee('/assets/js/meta-pixel.js', false);
    }

    public function test_admin_can_edit_identity_and_reset_staff_password_without_exposing_it(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create();
        $this->actingAs($admin)->put('/admin/users/'.$staff->id, [
            'name' => 'Updated Staff', 'username' => 'updated.staff', 'email' => 'updated@example.test', 'role' => 'staff', 'is_active' => true,
            'password' => 'NewPassword12345', 'password_confirmation' => 'NewPassword12345',
        ])->assertRedirect('/admin/users');
        $this->assertTrue(Hash::check('NewPassword12345', $staff->fresh()->password));
        $this->get('/admin/users')->assertInertia(fn (Assert $page) => $page->missing('users.data.0.password')->missing('users.data.1.password'));
        $this->put('/admin/users/'.$admin->id, ['name' => 'Updated Admin', 'username' => $admin->username, 'email' => 'admin-updated@example.test', 'role' => 'admin', 'is_active' => true])->assertRedirect('/admin/users');
        $this->assertSame('admin-updated@example.test', $admin->fresh()->email);
        // Email is optional; usernames are unique and limited to safe characters.
        $this->put('/admin/users/'.$staff->id, ['name' => 'Updated Staff', 'username' => $admin->username, 'email' => '', 'role' => 'staff', 'is_active' => true])->assertSessionHasErrors('username');
        $this->put('/admin/users/'.$staff->id, ['name' => 'Updated Staff', 'username' => 'no spaces!', 'email' => '', 'role' => 'staff', 'is_active' => true])->assertSessionHasErrors('username');
        $this->put('/admin/users/'.$staff->id, ['name' => 'Updated Staff', 'username' => 'Staff.Two', 'email' => '', 'role' => 'staff', 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertSame(['staff.two', null], [$staff->fresh()->username, $staff->fresh()->email]);
    }

    public function test_user_roles_and_password_rules_cannot_be_bypassed(): void
    {
        $this->actingAs($this->admin());
        $this->post('/admin/users', ['name' => 'Bad account', 'username' => 'bad', 'email' => 'bad@example.test', 'role' => 'super-admin', 'is_active' => true, 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors(['role', 'password']);
        $this->assertDatabaseCount('users', 1);
    }
}

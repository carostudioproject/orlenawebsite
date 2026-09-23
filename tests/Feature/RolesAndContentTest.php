<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Post;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RolesAndContentTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** A real 1x1 PNG, so uploads work without the GD extension. */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    private function home(): array
    {
        return json_decode(file_get_contents(resource_path('content/home.json')), true);
    }

    public function test_finance_reads_orders_only_and_content_editor_reaches_content_only(): void
    {
        $outlet = Outlet::create(['code' => 'T', 'name' => 'Test Outlet', 'address' => 'Address', 'is_active' => true]);
        $order = Order::create([
            'order_code' => 'ORL-300101-ROLES00001', 'checkout_key' => (string) Str::uuid(), 'owner_hash' => str_repeat('a', 64), 'request_hash' => str_repeat('b', 64),
            'customer_id' => Customer::create(['name' => 'Customer', 'whatsapp' => '6281234567890'])->id, 'outlet_id' => $outlet->id, 'outlet_name_snapshot' => $outlet->name,
            'fulfillment_method' => 'pickup', 'requested_date' => '2030-01-03', 'subtotal' => 1000, 'delivery_fee' => 0, 'total' => 1000,
        ]);
        $this->actingAs($this->user('finance'));
        $this->get('/admin/orders/'.$order->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.can.review', false));
        $this->post('/admin/orders/'.$order->id.'/review', ['review_version' => 0, 'delivery_fee' => 0, 'note' => 'x'])->assertForbidden();
        $this->post('/admin/orders/'.$order->id.'/cancel', ['from' => 'pending_review', 'cancel_reason' => 'x'])->assertForbidden();
        $this->assertSame('pending_review', $order->fresh()->order_status);
        $this->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page->whereNot('orders', null)->where('catalog', null)->where('content', null)
            ->where('auth.can.orders', true)->where('auth.can.review', false)->where('auth.user.role_label', 'Finance'));
        $this->get('/admin/orders')->assertOk();
        foreach (['/admin/products', '/admin/content', '/admin/posts', '/admin/users'] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->actingAs($this->user('content_editor'));
        $this->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page->where('orders', null)->where('catalog', null)->whereNot('content', null));
        foreach (['/admin/orders', '/admin/products', '/admin/users'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/admin/content')->assertOk();
        $this->get('/admin/posts')->assertOk()->assertInertia(fn (Assert $page) => $page->has('posts.data', 3));

        $this->actingAs($this->user('staff'));
        $this->get('/admin/content')->assertForbidden();
        $this->post('/admin/content/home', ['value' => $this->home()])->assertForbidden();
    }

    public function test_admin_can_assign_new_roles(): void
    {
        $this->actingAs($this->user('admin'));
        $payload = ['name' => 'Editor', 'username' => 'editor', 'email' => 'editor@example.test', 'role' => 'content_editor', 'is_active' => true, 'password' => 'Password12345', 'password_confirmation' => 'Password12345'];
        $this->post('/admin/users', $payload)->assertSessionHasNoErrors();
        $this->assertSame('content_editor', User::where('email', 'editor@example.test')->value('role')->value);

        $this->get('/admin/users?search=editor@')->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.email', 'editor@example.test'));
        $this->get('/admin/users?role=finance')->assertInertia(fn (Assert $page) => $page->has('users.data', 0));
        $this->get('/admin/users?role=super-admin')->assertSessionHasErrors('role');
    }

    public function test_homepage_copy_is_editable_and_resettable_to_the_approved_default(): void
    {
        $this->actingAs($this->user('content_editor'));
        $this->post('/admin/content/home', ['value' => [...$this->home(), 'missionTitle' => '']])->assertSessionHasErrors('value.missionTitle');
        $this->post('/admin/content/home', ['value' => [...$this->home(), 'missionTitle' => '  New mission  ', 'extra' => 'ignored']])->assertSessionHasNoErrors();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('home.missionTitle', 'New mission')->missing('home.extra')->has('blogs', 3));
        $this->assertDatabaseHas('audit_logs', ['action' => 'content.updated']);

        $this->post('/admin/content/home/reset')->assertRedirect('/admin/content/home');
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('home', $this->home()));
        $this->get('/admin/content/unknown')->assertNotFound();
    }

    public function test_hero_slides_accept_uploads_and_reject_foreign_image_paths(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user('content_editor'));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('hero', 7));
        $slide = ['alt' => 'New slide'];
        $this->post('/admin/content/hero', ['items' => [[...$slide, 'image' => 'https://evil.example/x.png']]])->assertSessionHasErrors('items.0.image');
        $this->post('/admin/content/hero', ['items' => [[...$slide, 'image' => '/assets/../../.env.png']]])->assertSessionHasErrors('items.0.image');
        $this->post('/admin/content/hero', ['items' => [[...$slide, 'image' => null]]])->assertSessionHasErrors('items.0.upload');
        $this->post('/admin/content/hero', ['items' => array_fill(0, 11, [...$slide, 'image' => '/assets/images/outlet1.jpg'])])->assertSessionHasErrors('items');
        $this->post('/admin/content/hero', ['items' => [[...$slide, 'image' => '/assets/images/outlet1.jpg', 'upload' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')]]])->assertSessionHasErrors('items.0.upload');

        $this->post('/admin/content/hero', ['items' => [[...$slide, 'upload' => $this->png('hero.png')], ['alt' => 'Kept', 'image' => '/assets/images/outlet1.jpg']]])->assertSessionHasNoErrors();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('hero', 2)->where('hero.1', ['image' => '/assets/images/outlet1.jpg', 'alt' => 'Kept'])
            ->where('hero.0', fn ($item) => str_starts_with($item['image'], '/storage/content/') && array_keys($item->all()) === ['image', 'alt']));
        $this->assertCount(1, Storage::disk('public')->files('content'));
    }

    public function test_baked_goods_show_only_the_categories_chosen_for_the_website(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user('admin'));
        $this->post('/admin/categories', ['name' => 'Brownies', 'upload' => $this->png('brownies.png')])->assertSessionHasNoErrors();
        $this->post('/admin/categories', ['name' => 'Cookies'])->assertSessionHasNoErrors();
        $this->post('/admin/categories', ['name' => 'SAUCE', 'is_active' => false])->assertSessionHasNoErrors();
        [$brownies, $cookies, $sauce] = [Category::where('name', 'Brownies')->sole(), Category::where('name', 'Cookies')->sole(), Category::where('name', 'SAUCE')->sole()];
        $this->assertStringStartsWith('/storage/content/', $brownies->image);
        $this->get('/admin/categories')->assertInertia(fn (Assert $page) => $page->where('records.data.0.image', $brownies->image));
        // New categories are not added to the homepage automatically, and the category form cannot do it.
        $this->post('/admin/categories', ['name' => 'Tart', 'show_on_website' => true])->assertSessionHasNoErrors();
        $this->assertFalse(Category::where('name', 'Tart')->value('show_on_website'));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('bakedGoods', 0));

        $this->actingAs($this->user('content_editor'));
        $this->get('/admin/content/bakedGoods')->assertInertia(fn (Assert $page) => $page->component('Admin/Content/Order')->where('section', 'bakedGoods')->has('items', 4));
        $this->post('/admin/content/bakedGoods', ['order' => [$cookies->id, $sauce->id, $brownies->id]])->assertSessionHasNoErrors();
        // Chosen and active only, in the chosen order; missing photos fall back to the logo; Tart stays off.
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('bakedGoods', 2)->where('bakedGoods.0', ['name' => 'Cookies', 'image' => '/assets/images/Orlena-Logo.png'])
            ->where('bakedGoods.1', ['name' => 'Brownies', 'image' => $brownies->image]));
        $this->post('/admin/categories/'.$sauce->id.'/toggle', ['is_active' => true])->assertForbidden();

        $this->actingAs($this->user('admin'));
        $this->post('/admin/categories/'.$sauce->id.'/toggle', ['is_active' => true])->assertSessionHasNoErrors();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('bakedGoods', 3)->where('bakedGoods.1.name', 'SAUCE'));

        // Removing a category from the website keeps it for products; an empty list hides the whole section.
        $this->actingAs($this->user('content_editor'));
        $this->post('/admin/content/bakedGoods', ['order' => [$brownies->id]])->assertSessionHasNoErrors();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('bakedGoods', [['name' => 'Brownies', 'image' => $brownies->image]]));
        $this->assertTrue($sauce->fresh()->is_active);
        $this->post('/admin/content/bakedGoods', ['order' => []])->assertSessionHasNoErrors();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('bakedGoods', 0));
    }

    public function test_inactive_categories_hide_their_products_from_ordering(): void
    {
        $category = Category::create(['name' => 'SAUCE']);
        Product::create(['category_id' => $category->id, 'sku' => 'S-1', 'name' => 'Nutella', 'price' => 18000, 'is_active' => true]);
        Outlet::create(['code' => 'o', 'name' => 'Outlet', 'address' => 'Jl', 'is_active' => true]);
        $this->get('/order')->assertInertia(fn (Assert $page) => $page->has('products', 1)->has('categories', 1));
        $category->update(['is_active' => false]);
        $this->get('/order')->assertInertia(fn (Assert $page) => $page->has('products', 0)->has('categories', 0));
    }

    public function test_homepage_journal_shows_only_the_five_newest_posts(): void
    {
        $this->actingAs($this->user('content_editor'));
        foreach (range(1, 4) as $n) {
            $this->post('/admin/posts', ['slug' => 'story-'.$n, 'title' => 'Story '.$n, 'excerpt' => 'Summary', 'image' => '/assets/images/outlet1.jpg',
                'content' => [['type' => 'paragraph', 'text' => 'Body']], 'is_published' => true])->assertSessionHasNoErrors();
        }
        // 3 approved articles + 4 new ones: the homepage keeps the 5 newest, the blog page lists all 7.
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('blogs', 5)->where('blogs.0.slug', 'story-4')->where('blogs.4.slug', 'orlena-cafe'));
        $this->get('/blog')->assertInertia(fn (Assert $page) => $page->has('blogs', 7));
    }

    public function test_about_page_copy_and_story_paragraphs_are_editable(): void
    {
        $this->actingAs($this->user('content_editor'));
        $about = json_decode(file_get_contents(resource_path('content/about.json')), true);
        $this->get('/about')->assertInertia(fn (Assert $page) => $page->where('about', $about));
        $this->post('/admin/content/about', ['value' => [...$about, 'storyBody' => []]])->assertSessionHasErrors('value.storyBody');
        $this->post('/admin/content/about', ['value' => [...$about, 'storyBody' => ['First', '']]])->assertSessionHasErrors('value.storyBody.1');
        $this->post('/admin/content/about', ['value' => [...$about, 'title' => 'Tentang Orlena', 'storyBody' => ['One', 'Two', 'Three']]])->assertSessionHasNoErrors();
        $this->get('/about')->assertInertia(fn (Assert $page) => $page->where('about.title', 'Tentang Orlena')->where('about.storyBody', ['One', 'Two', 'Three']));
        $this->post('/admin/content/about/reset');
        $this->get('/about')->assertInertia(fn (Assert $page) => $page->where('about', $about));
    }

    public function test_outlets_share_one_record_for_website_order_status_photo_and_preorder(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user('admin'));
        $outlet = ['code' => 'north', 'name' => 'North', 'address' => 'Jl. North', 'maps_url' => 'https://maps.app.goo.gl/north', 'is_active' => true];
        $this->post('/admin/outlets', [...$outlet, 'upload' => $this->png('north.png')])->assertSessionHasNoErrors();
        $this->post('/admin/outlets', [...$outlet, 'code' => 'south', 'name' => 'South', 'maps_url' => null, 'accepts_preorder' => false])->assertSessionHasNoErrors();
        $this->post('/admin/outlets', [...$outlet, 'code' => 'closed', 'name' => 'Closed', 'is_active' => false])->assertSessionHasNoErrors();
        [$north, $south, $closed] = [Outlet::where('code', 'north')->sole(), Outlet::where('code', 'south')->sole(), Outlet::where('code', 'closed')->sole()];
        $this->assertTrue($north->accepts_preorder);
        $this->assertStringStartsWith('/storage/content/', $north->image);

        // New outlets are not on the website until chosen there; the order form needs "Aktif" and "Bisa PO".
        $this->get('/about')->assertInertia(fn (Assert $page) => $page->has('outlets', 0));
        $this->get('/order')->assertInertia(fn (Assert $page) => $page->has('outlets', 1)->where('outlets.0.name', 'North'));

        $this->actingAs($this->user('content_editor'));
        $this->get('/admin/content/outlets')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Content/Order')->where('section', 'outlets')->has('items', 3));
        $this->post('/admin/content/outlets', ['order' => [$south->id, $north->id, $north->id]])->assertSessionHasErrors('order.1');
        $this->post('/admin/content/outlets', ['order' => [$south->id, $closed->id, $north->id]])->assertSessionHasNoErrors();
        // Shown in the chosen order; the inactive outlet stays hidden even though it was chosen.
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('outlets', 2)->where('outlets.0.name', 'South')->where('outlets.0.mapsUrl', '')->where('outlets.1.alt', 'North'));
        $this->post('/admin/outlets/'.$closed->id.'/toggle', ['is_active' => true])->assertForbidden();

        $this->actingAs($this->user('admin'));
        $this->post('/admin/outlets/'.$closed->id.'/toggle', ['is_active' => true])->assertSessionHasNoErrors();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->has('outlets', 3)->where('outlets.1.name', 'Closed'));
        $this->post('/admin/categories/1/toggle', ['is_active' => false])->assertNotFound();
    }

    public function test_products_have_photos_ten_row_pages_and_quick_activation(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user('admin'));
        $category = Category::create(['name' => 'Test']);
        foreach (range(1, 12) as $n) {
            Product::create(['category_id' => $category->id, 'sku' => 'P-'.$n, 'name' => 'Product '.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'price' => $n === 12 ? null : 10000, 'is_active' => false]);
        }
        $this->get('/admin/products')->assertInertia(fn (Assert $page) => $page->has('records.data', 10)->where('records.total', 12));
        $product = Product::where('sku', 'P-1')->sole();
        $this->put('/admin/products/'.$product->id, ['name' => 'Photo', 'sku' => 'P-1', 'category_id' => $category->id, 'price' => 10000, 'is_active' => false, 'upload' => $this->png('p.png')])->assertSessionHasNoErrors();
        $this->assertStringStartsWith('/storage/content/', $product->fresh()->image);
        $this->put('/admin/products/'.$product->id, ['name' => 'Photo', 'sku' => 'P-1', 'category_id' => $category->id, 'price' => 10000, 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertStringStartsWith('/storage/content/', $product->fresh()->image, 'Saving without a new photo keeps the current one.');

        $this->post('/admin/products/'.$product->id.'/toggle', ['is_active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($product->fresh()->is_active);
        $this->post('/admin/products/'.Product::where('sku', 'P-12')->value('id').'/toggle', ['is_active' => true])->assertSessionHasErrors('is_active');
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.activated', 'subject_id' => $product->id]);

        $this->actingAs($this->user('staff'));
        $this->post('/admin/products/'.$product->id.'/toggle', ['is_active' => false])->assertForbidden();
    }

    public function test_drafts_stay_private_and_published_posts_lead_the_journal(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user('content_editor'));
        $post = ['slug' => 'new-story', 'category' => 'News', 'title' => 'New Story', 'excerpt' => 'Short summary',
            'content' => [['type' => 'heading', 'text' => 'Hello'], ['type' => 'paragraph', 'text' => 'Body <script>x</script>']], 'is_published' => false];
        $this->post('/admin/posts', [...$post, 'slug' => 'Bad Slug'])->assertSessionHasErrors('slug');
        $this->post('/admin/posts', $post)->assertSessionHasErrors('upload');
        $this->post('/admin/posts', [...$post, 'slug' => 'orlena-cafe', 'upload' => $this->png('c.png')])->assertSessionHasErrors('slug');
        $this->post('/admin/posts', [...$post, 'content' => [['type' => 'script', 'text' => 'x']], 'upload' => $this->png('c.png')])->assertSessionHasErrors('content.0.type');
        $this->post('/admin/posts', [...$post, 'upload' => $this->png('cover.png')])->assertSessionHasNoErrors();
        $created = Post::where('slug', 'new-story')->sole();

        $this->get('/blog/new-story')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('/blog/new-story', false);
        $this->get('/blog')->assertInertia(fn (Assert $page) => $page->has('blogs', 3));

        $this->put('/admin/posts/'.$created->id, [...$post, 'category' => null, 'image' => $created->image, 'is_published' => true])->assertSessionHasNoErrors();
        $this->get('/blog/new-story')->assertOk()->assertInertia(fn (Assert $page) => $page->where('blog.content.1.text', 'Body <script>x</script>')->missing('blog.category'));
        $this->get('/blog')->assertInertia(fn (Assert $page) => $page->has('blogs', 4)->where('blogs.0.slug', 'new-story'));
        $this->get('/sitemap.xml')->assertSee('/blog/new-story', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'post.updated']);
    }
}

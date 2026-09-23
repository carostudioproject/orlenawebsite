<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.connections.mysql.database') !== 'orlena_test') {
            throw new \RuntimeException('Only orlena_test is allowed.');
        }
    }

    public function test_public_routes_and_server_metadata(): void
    {
        foreach (['/' => 'Home', '/about' => 'About', '/blog' => 'BlogIndex'] as $path => $page) {
            $this->get($path)->assertOk()->assertSee('name="description"', false)
                ->assertInertia(fn (Assert $view) => $view->component('Public/'.$page)->has('seo.canonical'));
        }
    }

    public function test_all_articles_use_the_same_complete_source(): void
    {
        $blogs = json_decode(file_get_contents(resource_path('content/blogs.json')), true);
        foreach ($blogs as $blog) {
            $this->get('/blog/'.$blog['slug'])->assertOk()
                ->assertInertia(fn (Assert $view) => $view->component('Public/BlogShow')->where('blog', $blog)->where('seo.title', $blog['title']));
        }
    }

    public function test_missing_articles_return_404_and_old_story_route_redirects(): void
    {
        $this->get('/blog/missing-article')->assertNotFound();
        $this->get('/missing-page')->assertNotFound();
        $this->get('/our-story')->assertStatus(301)->assertRedirect('/about');
    }

    public function test_staging_is_not_indexed_and_sitemap_contains_articles(): void
    {
        config(['site.indexable' => false]);
        $this->get('/')->assertSee('noindex,nofollow', false);
        $this->get('/robots.txt')->assertSee('Disallow: /', false);
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml')->assertSee('/blog/orlena-cafe', false);
        config(['site.indexable' => true]);
        $this->get('/robots.txt')->assertSee('Sitemap:', false);
        $this->get('/')->assertSee('content="index,follow"', false);
    }
}

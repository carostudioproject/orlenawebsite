<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** Parses the raw server response, exactly what a crawler sees before any JavaScript runs. */
    private function dom(string $path): DOMXPath
    {
        $html = $this->get($path)->assertOk()->getContent();
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    private function text(DOMXPath $xpath, string $query): string
    {
        return preg_replace('/\s+/u', '', $xpath->query($query)->item(0)?->textContent ?? '');
    }

    public function test_public_pages_are_complete_html_with_the_approved_copy(): void
    {
        // Same data the baseline was captured with: approved content defaults plus the catalog seeders.
        $this->seed();
        $baseline = json_decode(file_get_contents(base_path('tests/fixtures/public-pages.json')), true)['pages'];
        foreach ($baseline as $path => $expected) {
            $xpath = $this->dom($path);
            $this->assertSame($expected['title'], $xpath->query('//title')->item(0)->textContent, $path);
            $this->assertSame($expected['main'], $this->text($xpath, '//main[@id="main-content"]'), $path.' main text');
            $this->assertSame($expected['header'], $this->text($xpath, '//header[contains(@class,"header-navbar")]'), $path.' header');
            $this->assertSame($expected['footer'], $this->text($xpath, '//footer'), $path.' footer');
            $this->assertSame($expected['h1'], array_map(fn ($h) => trim($h->textContent), iterator_to_array($xpath->query('//h1'))), $path.' h1');
            $this->assertSame($expected['links'], array_map(fn ($a) => $a->getAttribute('href'), iterator_to_array($xpath->query('//main//a[@href]'))), $path.' links');
            $this->assertSame($expected['images'], array_map(fn ($i) => [$i->getAttribute('src'), $i->getAttribute('alt')], iterator_to_array($xpath->query('//main//img'))), $path.' images');
            // No Inertia app on public pages: nothing depends on JavaScript to show the content.
            $this->assertSame(0, $xpath->query('//*[@data-page]')->length, $path);
        }
    }

    public function test_server_metadata_and_structured_data(): void
    {
        $this->seed();
        $xpath = $this->dom('/');
        $this->assertSame(url('/'), $xpath->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'));
        $this->assertNotEmpty($xpath->query('//meta[@name="description"]')->item(0)->getAttribute('content'));
        $this->assertSame(url('/assets/images/Orlena-Logo.png'), $xpath->query('//meta[@property="og:image"]')->item(0)->getAttribute('content'));
        $business = json_decode($xpath->query('//script[@type="application/ld+json"]')->item(0)->textContent, true);
        $this->assertSame(['Bakery', 'Orlena', 5], [$business['@type'], $business['name'], count($business['department'])]);
        $this->assertSame('ID', $business['department'][0]['address']['addressCountry']);

        $xpath = $this->dom('/blog/orlena-cafe');
        $this->assertSame('article', $xpath->query('//meta[@property="og:type"]')->item(0)->getAttribute('content'));
        [$posting, $breadcrumbs] = array_map(fn ($node) => json_decode($node->textContent, true), iterator_to_array($xpath->query('//script[@type="application/ld+json"]')));
        $this->assertSame(['BlogPosting', url('/blog/orlena-cafe')], [$posting['@type'], $posting['url']]);
        $this->assertSame('BreadcrumbList', $breadcrumbs['@type']);
    }

    public function test_all_articles_use_the_same_complete_source(): void
    {
        $blogs = json_decode(file_get_contents(resource_path('content/blogs.json')), true);
        foreach ($blogs as $blog) {
            $xpath = $this->dom('/blog/'.$blog['slug']);
            $this->assertSame($blog['title'], $xpath->query('//title')->item(0)->textContent);
            $this->assertSame($blog['title'], trim($xpath->query('//h1')->item(0)->textContent));
            $body = $this->text($xpath, '//div[contains(@class,"blog-detail-content")]');
            $this->assertSame(preg_replace('/\s+/u', '', implode('', array_column($blog['content'], 'text'))), $body, $blog['slug']);
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

    public function test_order_pages_share_the_server_rendered_header_and_footer(): void
    {
        $xpath = $this->dom('/order');
        $this->assertSame(1, $xpath->query('//header[contains(@class,"header-navbar")]')->length);
        $this->assertSame(1, $xpath->query('//footer[@id="footer"]')->length);
        $this->assertSame(1, $xpath->query('//main[@id="main-content"]//*[@data-page]')->length);
        $this->assertSame('noindex,nofollow', $xpath->query('//meta[@name="robots"]')->item(0)->getAttribute('content'));
        // The dashboard keeps its own layout.
        $this->get('/admin/login')->assertDontSee('header-navbar', false);
    }
}

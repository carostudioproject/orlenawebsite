<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Support\SiteContent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * Public website pages render as complete HTML on the server (Blade), so search engines and link previews
 * read the full text without running JavaScript. Order and dashboard pages stay on Inertia.
 */
class PublicPageController extends Controller
{
    public function __construct(private SiteContent $content) {}

    public function home(): View
    {
        $outlets = $this->content->outlets();

        return $this->page('home', 'Orlena', 'Orlena is a Bali-based dessert brownie brand founded in 2018, offering rich, fudgy treats with unique flavors loved by locals and tourists. From a small home kitchen to multiple outlets, Orlena blends quality, creativity, and community care—spreading happiness one bite at a time.', [
            'home' => $this->content->get('home'), 'hero' => $this->content->get('hero'), 'bakedGoods' => $this->content->bakedGoods(),
            'outlets' => $outlets, 'brandCollaborations' => $this->content->get('collaborations'), 'blogs' => $this->content->articles(SiteContent::HOME_ARTICLES),
        ], structuredData: [$this->business($outlets)]);
    }

    public function about(): View
    {
        $outlets = $this->content->outlets();

        return $this->page('about', 'About Orlena', 'Established in 2018, under the umbrella of PT Orlena Delapan Mulia, Orlena is a dessert brownie brand from Bali.', [
            'about' => $this->content->get('about'), 'outlets' => $outlets,
        ], structuredData: [$this->business($outlets)]);
    }

    public function blog(): View
    {
        return $this->page('blog-index', "What's on Orlena", 'Stories, updates, and moments from our journey.', ['blogs' => $this->content->articles()]);
    }

    public function article(string $slug): View
    {
        $post = Post::published()->where('slug', $slug)->first();
        abort_unless($post, 404);
        $blog = $post->toArticle();
        $posting = [
            '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $blog['title'], 'description' => $blog['excerpt'],
            'image' => [url($blog['image'])], 'url' => url('/blog/'.$blog['slug']), 'mainEntityOfPage' => url('/blog/'.$blog['slug']),
            'datePublished' => $post->created_at?->toIso8601String(), 'dateModified' => $post->updated_at?->toIso8601String(),
            'articleSection' => $blog['category'] ?? null,
            'author' => ['@type' => 'Organization', 'name' => 'Orlena', 'url' => url('/')],
            'publisher' => ['@type' => 'Organization', 'name' => 'Orlena', 'logo' => ['@type' => 'ImageObject', 'url' => url('/assets/images/Orlena-Logo.png')]],
        ];
        $breadcrumbs = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => "What's on Orlena", 'item' => url('/blog')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $blog['title'], 'item' => url('/blog/'.$blog['slug'])],
        ]];

        return $this->page('blog-show', $blog['title'], $blog['excerpt'], ['blog' => $blog], $blog['image'], 'article', [array_filter($posting, fn ($value) => $value !== null), $breadcrumbs]);
    }

    public function sitemap(): Response
    {
        $paths = ['/', '/about', '/blog'];
        foreach ($this->content->articles() as $blog) {
            $paths[] = '/blog/'.$blog['slug'];
        }

        return response()->view('sitemap', ['paths' => $paths])->header('Content-Type', 'application/xml');
    }

    public function robots(): Response
    {
        $text = config('site.indexable')
            ? "User-agent: *\nAllow: /\nDisallow: /admin\nSitemap: ".url('/sitemap.xml')."\n"
            : "User-agent: *\nDisallow: /\n";

        return response($text)->header('Content-Type', 'text/plain');
    }

    /** Orlena and its outlets as schema.org Bakery data, for search results and maps. */
    private function business(array $outlets): array
    {
        $social = $this->content->get('social');

        return array_filter([
            '@context' => 'https://schema.org', '@type' => 'Bakery', 'name' => 'Orlena', 'url' => url('/'),
            'logo' => url('/assets/images/Orlena-Logo.png'), 'image' => url('/assets/images/Orlena-Logo.png'),
            'description' => 'Bali-based dessert brownie brand founded in 2018.',
            'sameAs' => array_values(array_filter([$social['instagramUrl'] ?? null, $social['tiktokUrl'] ?? null])) ?: null,
            'department' => array_map(fn (array $outlet) => array_filter([
                '@type' => 'Bakery', 'name' => $outlet['name'], 'image' => url($outlet['image']),
                'address' => ['@type' => 'PostalAddress', 'streetAddress' => $outlet['address'], 'addressRegion' => 'Bali', 'addressCountry' => 'ID'],
                'hasMap' => $outlet['mapsUrl'] ?: null,
            ]), $outlets) ?: null,
        ], fn ($value) => $value !== null);
    }

    private function page(string $view, string $title, string $description, array $props = [], string $image = '/assets/images/Orlena-Logo.png', string $type = 'website', array $structuredData = []): View
    {
        $seo = ['title' => $title, 'description' => $description, 'canonical' => url()->current(), 'image' => url($image), 'type' => $type, 'indexable' => (bool) config('site.indexable')];

        return view('pages.'.$view, [...$props, 'seo' => $seo, 'site' => $this->content->get('social'), 'structuredData' => $structuredData]);
    }
}

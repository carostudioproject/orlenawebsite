<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Support\SiteContent;
use Inertia\Inertia;
use Inertia\Response;

class PublicPageController extends Controller
{
    public function __construct(private SiteContent $content) {}

    public function home(): Response
    {
        return $this->page('Home', 'Orlena', 'Orlena is a Bali-based dessert brownie brand founded in 2018, offering rich, fudgy treats with unique flavors loved by locals and tourists. From a small home kitchen to multiple outlets, Orlena blends quality, creativity, and community care—spreading happiness one bite at a time.', [
            'home' => $this->content->get('home'), 'hero' => $this->content->get('hero'), 'bakedGoods' => $this->content->bakedGoods(),
            'outlets' => $this->content->outlets(), 'brandCollaborations' => $this->content->get('collaborations'), 'blogs' => $this->content->articles(SiteContent::HOME_ARTICLES),
        ]);
    }

    public function about(): Response
    {
        return $this->page('About', 'About Orlena', 'Established in 2018, under the umbrella of PT Orlena Delapan Mulia, Orlena is a dessert brownie brand from Bali.', ['about' => $this->content->get('about'), 'outlets' => $this->content->outlets()]);
    }

    public function blog(): Response
    {
        return $this->page('BlogIndex', "What's on Orlena", 'Stories, updates, and moments from our journey.', ['blogs' => $this->content->articles()]);
    }

    public function article(string $slug): Response
    {
        $blog = Post::published()->where('slug', $slug)->first()?->toArticle();
        abort_unless($blog, 404);

        return $this->page('BlogShow', $blog['title'], $blog['excerpt'], ['blog' => $blog], $blog['image']);
    }

    public function sitemap(): \Illuminate\Http\Response
    {
        $paths = ['/', '/about', '/blog'];
        foreach ($this->content->articles() as $blog) {
            $paths[] = '/blog/'.$blog['slug'];
        }

        return response()->view('sitemap', ['paths' => $paths])->header('Content-Type', 'application/xml');
    }

    public function robots(): \Illuminate\Http\Response
    {
        $text = config('site.indexable')
            ? "User-agent: *\nAllow: /\nDisallow: /admin\nSitemap: ".url('/sitemap.xml')."\n"
            : "User-agent: *\nDisallow: /\n";

        return response($text)->header('Content-Type', 'text/plain');
    }

    private function page(string $component, string $title, string $description, array $props = [], string $image = '/assets/images/Orlena-Logo.png'): Response
    {
        $seo = ['title' => $title, 'description' => $description, 'canonical' => url()->current(), 'image' => url($image), 'indexable' => (bool) config('site.indexable')];

        return Inertia::render('Public/'.$component, [...$props, 'seo' => $seo])->withViewData('seo', $seo);
    }
}

<?php

namespace App\Support;

use App\Models\Category;
use App\Models\ContentEntry;
use App\Models\Outlet;
use App\Models\Post;

/**
 * Editable website content. Approved defaults live in resources/content; the dashboard stores overrides.
 */
class SiteContent
{
    /** Section key => default file in resources/content. */
    public const SECTIONS = [
        'hero' => 'hero.json', 'home' => 'home.json', 'collaborations' => 'collaborations.json', 'about' => 'about.json',
        'social' => 'social.json',
    ];

    /** Homepage journal shows only the newest stories; the blog page lists them all. */
    public const HOME_ARTICLES = 5;

    public function get(string $section): array
    {
        return ContentEntry::where('key', $section)->value('value') ?? $this->defaults($section);
    }

    public function isCustomized(string $section): bool
    {
        return ContentEntry::where('key', $section)->exists();
    }

    public function defaults(string $section): array
    {
        $defaults = json_decode(file_get_contents(resource_path('content/'.self::SECTIONS[$section])), true, 512, JSON_THROW_ON_ERROR);

        // Until staff save their own contact details, the environment's WhatsApp number still applies.
        return $section === 'social' ? [...$defaults, 'whatsappNumber' => (string) config('site.whatsapp_number', $defaults['whatsappNumber'])] : $defaults;
    }

    /** Outlet cards: operating outlets chosen under website content, in the order set there. */
    public function outlets(): array
    {
        return Outlet::where('is_active', true)->where('show_on_website', true)->orderBy('position')->orderBy('name')->get()->map(fn (Outlet $outlet) => [
            'name' => $outlet->name, 'address' => $outlet->address, 'image' => $outlet->image ?? '/assets/images/Orlena-Logo.png',
            'alt' => $outlet->name, 'mapsUrl' => $outlet->maps_url ?? '',
        ])->all();
    }

    /** Baked Goods cards: active categories chosen under website content, in the order set there. */
    public function bakedGoods(): array
    {
        return Category::where('is_active', true)->where('show_on_website', true)->orderBy('position')->orderBy('name')->get()
            ->map(fn (Category $category) => ['name' => $category->name, 'image' => $category->image ?? '/assets/images/Orlena-Logo.png'])->all();
    }

    public function articles(?int $limit = null): array
    {
        return Post::published()->when($limit, fn ($query) => $query->limit($limit))->get()->map->toArticle()->all();
    }
}

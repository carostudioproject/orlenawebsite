<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ContentEntry;
use App\Models\Outlet;
use App\Models\Post;
use App\Support\Audit;
use App\Support\ContentImage;
use App\Support\SiteContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ContentController extends Controller
{
    /** List sections: public keys per item in the order the website expects (null = image), and the item limit. */
    private const LISTS = [
        'hero' => ['fields' => ['image' => null, 'alt' => 160], 'max' => 10],
        'collaborations' => ['fields' => ['name' => 80, 'image' => null, 'headline' => 200, 'brief' => 500], 'max' => 20],
    ];

    /** Sections whose cards come from catalog records; the website chooses which ones appear and their order. */
    private const ORDERED = ['outlets' => Outlet::class, 'bakedGoods' => Category::class];

    /** Object sections: max length per field; paragraph lists are arrays of strings. */
    private const OBJECTS = [
        'home' => ['missionTitle' => 200, 'missionDescription' => 600, 'bakedGoodsTitle' => 200, 'outletsTitle' => 200, 'collaborationTitle' => 200, 'blogTitle' => 200, 'blogSubtitle' => 200],
        'about' => ['title' => 200, 'description' => 1500, 'storyTitleFirst' => 40, 'storyTitleSecond' => 40, 'storyLead' => 600, 'storyBody' => 'paragraphs', 'storyClosing' => 300, 'outletsTitle' => 200],
        // Empty social links hide that link on every page; the WhatsApp number is always required.
        'social' => ['instagramUrl' => 'url', 'tiktokUrl' => 'url', 'whatsappNumber' => 'phone'],
    ];

    public function __construct(private SiteContent $content) {}

    public function index()
    {
        $entries = ContentEntry::leftJoin('users', 'users.id', '=', 'content_entries.updated_by')
            ->get(['key', 'content_entries.updated_at', 'users.name as editor'])->keyBy('key');

        return Inertia::render('Admin/Content/Index', [
            'sections' => collect(array_keys(SiteContent::SECTIONS))->map(fn ($key) => [
                'key' => $key, 'customized' => $entries->has($key),
                'updated_at' => $entries->get($key)?->updated_at?->toIso8601String(), 'editor' => $entries->get($key)?->editor,
            ]),
            'outlets' => ['total' => Outlet::count(), 'shown' => Outlet::where('is_active', true)->where('show_on_website', true)->count()],
            'categories' => ['total' => Category::count(), 'shown' => Category::where('is_active', true)->where('show_on_website', true)->count()],
            'posts' => ['total' => Post::count(), 'published' => Post::where('is_published', true)->count()],
        ]);
    }

    public function edit(string $section)
    {
        if ($model = self::ORDERED[$section] ?? null) {
            return Inertia::render('Admin/Content/Order', [
                'section' => $section,
                // Cards on the website first, in their order; the rest can be added from the existing records.
                'items' => $model::orderByDesc('show_on_website')->orderBy('position')->orderBy('name')
                    ->get($section === 'outlets' ? ['id', 'name', 'address', 'image', 'is_active', 'show_on_website'] : ['id', 'name', 'image', 'is_active', 'show_on_website']),
            ]);
        }
        $this->ensureSection($section);

        return Inertia::render('Admin/Content/Edit', [
            'section' => $section, 'value' => $this->content->get($section), 'customized' => $this->content->isCustomized($section),
        ]);
    }

    public function update(Request $request, string $section)
    {
        if (isset(self::ORDERED[$section])) {
            return $this->reorder($request, $section);
        }
        $this->ensureSection($section);
        $value = isset(self::OBJECTS[$section]) ? $this->objectCopy($request, $section) : $this->listItems($request, $section);
        DB::transaction(function () use ($section, $value, $request) {
            $entry = ContentEntry::updateOrCreate(['key' => $section], ['value' => $value, 'updated_by' => $request->user()->id]);
            Audit::record('content.updated', $entry, ['section' => $section]);
        });

        return redirect('/admin/content/'.$section)->with('success', 'Content saved and live on the website.');
    }

    public function reset(Request $request, string $section)
    {
        $this->ensureSection($section);
        DB::transaction(function () use ($section) {
            if ($entry = ContentEntry::where('key', $section)->lockForUpdate()->first()) {
                $entry->delete();
                Audit::record('content.reset', $entry, ['section' => $section]);
            }
        });

        return redirect('/admin/content/'.$section)->with('success', 'Content restored to the approved original copy.');
    }

    private function ensureSection(string $section): void
    {
        abort_unless(array_key_exists($section, SiteContent::SECTIONS), 404);
    }

    /** Outlet and category details live in the catalog menus; the website section chooses which appear and in what order. */
    private function reorder(Request $request, string $section)
    {
        $model = self::ORDERED[$section];
        $table = (new $model)->getTable();
        // The submitted list is exactly what the website shows, in this order; everything else is hidden.
        $ids = $request->validate(['order' => ['present', 'array', 'max:200'], 'order.*' => ['integer', 'distinct', Rule::exists($table, 'id')]])['order'];
        DB::transaction(function () use ($ids, $model, $table) {
            $model::query()->whereKeyNot($ids)->update(['show_on_website' => false]);
            foreach (array_values($ids) as $position => $id) {
                $model::whereKey($id)->update(['show_on_website' => true, 'position' => $position]);
            }
            if ($subject = $model::query()->first()) {
                Audit::record($table.'.website_updated', $subject, ['shown' => array_values($ids)]);
            }
        });

        return redirect('/admin/content/'.$section)->with('success', 'Website display updated.');
    }

    private function objectCopy(Request $request, string $section): array
    {
        $fields = self::OBJECTS[$section];
        $rules = [];
        foreach ($fields as $key => $max) {
            $rules["value.$key"] = match ($max) {
                'paragraphs' => ['required', 'array', 'min:1', 'max:10'],
                'url' => ['nullable', 'url:https', 'max:500'],
                'phone' => ['required', 'regex:/^[1-9][0-9]{7,14}$/'],
                default => ['required', 'string', 'max:'.$max],
            };
            if ($max === 'paragraphs') {
                $rules["value.$key.*"] = ['required', 'string', 'max:1500'];
            }
        }
        $data = $request->validate($rules, [
            'required' => 'This field is required.', 'max' => 'Up to :max characters.', 'min' => 'At least one paragraph.',
            'url' => 'Use a valid https link, or leave it empty to hide it.', 'regex' => 'Use the 62xxxxxxxxxx format without spaces or +.',
        ]);

        return collect($fields)->map(fn ($max, $key) => $max === 'paragraphs'
            ? collect($data['value'][$key])->map(fn ($text) => trim($text))->values()->all()
            : trim((string) ($data['value'][$key] ?? '')))->all();
    }

    private function listItems(Request $request, string $section): array
    {
        ['fields' => $fields, 'max' => $limit] = self::LISTS[$section];
        $rules = ['items' => ['required', 'array', 'min:1', 'max:'.$limit], 'items.*.upload' => ContentImage::RULES, 'items.*.image' => ContentImage::PATH];
        foreach ($fields as $field => $max) {
            if ($max !== null) {
                $rules["items.*.$field"] = ['required', 'string', 'max:'.$max];
            }
        }
        $data = $request->validate($rules, [
            'required' => 'This field is required.', 'max' => 'Up to :max characters.',
            'items.*.upload.image' => 'The file must be a JPG, PNG or WebP image.', 'items.*.upload.max' => 'The image may be at most 4 MB.',
            'items.*.image.regex' => 'Invalid image. Please upload it again.', 'items.max' => 'Up to :max items.',
        ]);

        return collect($data['items'])->map(function ($item, $index) use ($fields, $request) {
            $image = ContentImage::resolve($request->file("items.$index.upload"), $item['image'] ?? null);
            if (! $image) {
                throw ValidationException::withMessages(["items.$index.upload" => 'Upload an image for this item.']);
            }

            return collect($fields)->map(fn ($max, $field) => $field === 'image' ? $image : trim($item[$field]))->all();
        })->values()->all();
    }
}

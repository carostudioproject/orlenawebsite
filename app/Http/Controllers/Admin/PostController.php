<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Support\Audit;
use App\Support\ContentImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;
        $posts = Post::with('editor:id,name')->when($search, fn ($q) => $q->where('title', 'like', '%'.$search.'%'))
            ->orderBy('position')->orderByDesc('id')->paginate(10, ['id', 'slug', 'title', 'category', 'is_published', 'updated_by', 'updated_at'])->withQueryString();

        return Inertia::render('Admin/Posts/Index', ['posts' => $posts, 'filters' => ['search' => $search]]);
    }

    public function create()
    {
        return Inertia::render('Admin/Posts/Form', ['record' => null]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $post = DB::transaction(function () use ($data, $request) {
            // New stories appear first, matching the newest-first journal order.
            $post = Post::create([...$data, 'position' => (int) Post::min('position') - 1, 'updated_by' => $request->user()->id]);
            Audit::record('post.created', $post, ['slug' => $post->slug, 'is_published' => $post->is_published]);

            return $post;
        });

        return redirect('/admin/posts/'.$post->id.'/edit')->with('success', 'Article saved.');
    }

    public function edit(Post $post)
    {
        return Inertia::render('Admin/Posts/Form', ['record' => $post]);
    }

    public function update(Request $request, Post $post)
    {
        $data = $this->validated($request, $post);
        DB::transaction(function () use ($post, $data, $request) {
            $post->update([...$data, 'updated_by' => $request->user()->id]);
            Audit::record('post.updated', $post, array_intersect_key($post->getChanges(), array_flip(['slug', 'is_published'])));
        });

        return redirect('/admin/posts/'.$post->id.'/edit')->with('success', 'Article updated.');
    }

    private function validated(Request $request, ?Post $post = null): array
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('posts')->ignore($post)],
            'category' => ['nullable', 'string', 'max:120'], 'title' => ['required', 'string', 'max:200'], 'excerpt' => ['required', 'string', 'max:500'],
            'image' => ContentImage::PATH, 'upload' => ContentImage::RULES,
            'content' => ['required', 'array', 'min:1', 'max:100'], 'content.*.type' => ['required', 'in:paragraph,heading'],
            'content.*.text' => ['required', 'string', 'max:5000'], 'is_published' => ['required', 'boolean'],
        ], [
            'required' => 'This field is required.', 'max' => 'Up to :max characters.', 'content.min' => 'Add at least one paragraph.',
            'slug.regex' => 'Use lowercase letters, numbers and dashes, e.g. orlena-cafe.', 'slug.unique' => 'This slug is already used by another article.',
            'upload.image' => 'The file must be a JPG, PNG or WebP image.', 'upload.max' => 'The image may be at most 4 MB.',
        ]);
        $image = ContentImage::resolve($request->file('upload'), $data['image'] ?? $post?->image);
        if (! $image) {
            throw ValidationException::withMessages(['upload' => 'Upload a cover image.']);
        }

        return [
            'slug' => $data['slug'], 'category' => isset($data['category']) ? trim($data['category']) : null, 'title' => trim($data['title']), 'excerpt' => trim($data['excerpt']), 'image' => $image,
            'content' => collect($data['content'])->map(fn ($block) => ['type' => $block['type'], 'text' => trim($block['text'])])->values()->all(),
            'is_published' => (bool) $data['is_published'],
        ];
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('category', 120)->nullable();
            $table->string('title', 200);
            $table->text('excerpt');
            $table->string('image', 500);
            $table->json('content');
            $table->boolean('is_published')->default(false)->index();
            $table->integer('position')->default(0)->index();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        // Homepage copy, outlets, and collaborations; a missing key means the approved default in resources/content applies.
        Schema::create('content_entries', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // The approved articles become the first published posts, keeping their order and exact copy.
        $blogs = json_decode(file_get_contents(resource_path('content/blogs.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($blogs as $position => $blog) {
            DB::table('posts')->insert([
                'slug' => $blog['slug'], 'category' => $blog['category'] ?? null, 'title' => $blog['title'], 'excerpt' => $blog['excerpt'],
                'image' => $blog['image'], 'content' => json_encode($blog['content'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'is_published' => true, 'position' => $position, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_entries');
        Schema::dropIfExists('posts');
    }
};

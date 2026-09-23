<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'array', 'is_published' => 'boolean', 'position' => 'integer'];
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true)->orderBy('position')->orderBy('id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The public article shape shared by the homepage, listing, detail page, and SEO. */
    public function toArticle(): array
    {
        // Category is optional, and the approved source omits the key entirely when absent.
        return array_filter(['slug' => $this->slug, 'category' => $this->category, 'title' => $this->title, 'excerpt' => $this->excerpt, 'image' => $this->image, 'content' => $this->content], fn ($value) => $value !== null);
    }
}

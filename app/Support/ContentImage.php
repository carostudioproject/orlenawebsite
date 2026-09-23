<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

class ContentImage
{
    /** Upload rules shared by every content image field. */
    public const RULES = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'];

    /** Existing images must be site assets or earlier uploads; arbitrary URLs are rejected. */
    public const PATH = ['nullable', 'string', 'max:500', 'regex:#^/(assets|storage/content)/[^?\#]+\.(png|jpe?g|webp)$#i', 'not_regex:#\.\.#'];

    public static function resolve(?UploadedFile $upload, ?string $current): ?string
    {
        return $upload ? '/storage/'.$upload->store('content', 'public') : $current;
    }
}

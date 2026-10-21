<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * srcset for the website's own photos, so phones download a small copy instead of the full banner.
 *
 * scripts/make-image-sizes.mjs writes "photo.w480.webp", "photo.w800.webp", "photo.w1200.webp" beside
 * "/assets/images/…/photo.png"; this lists the copies that exist plus the original at its own width (served
 * as WebP through the .htaccess twin rule). Uploaded photos and photos without copies get no srcset.
 * Only file headers and file existence are read, so no cache is needed and new copies show up at once.
 */
class ResponsiveImage
{
    private const WIDTHS = [480, 800, 1200];

    /** The srcset value, or null when the image has no smaller copies. */
    public static function srcset(?string $src): ?string
    {
        if (! $src || ! preg_match('#^/assets/images/[^?\#]+\.(png|jpe?g)$#i', $src) || str_contains($src, '..')) {
            return null;
        }

        $file = public_path(ltrim($src, '/'));
        $size = @getimagesize($file);
        if (! $size) {
            return null;
        }
        $base = substr($src, 0, -strlen(pathinfo($src, PATHINFO_EXTENSION)) - 1);
        $entries = [];
        foreach (self::WIDTHS as $width) {
            if ($width < $size[0] && is_file(public_path(ltrim($base, '/').".w{$width}.webp"))) {
                $entries[] = self::url("{$base}.w{$width}.webp")." {$width}w";
            }
        }

        return $entries ? implode(', ', [...$entries, self::url($src)." {$size[0]}w"]) : null;
    }

    /**
     * ` srcset="…" sizes="…"` for an <img> tag, or nothing when there are no copies.
     * $sizes is how wide the image shows, e.g. "(max-width: 767px) 80vw, 720px".
     */
    public static function attrs(?string $src, string $sizes): HtmlString
    {
        $set = self::srcset($src);

        return new HtmlString($set ? ' srcset="'.e($set).'" sizes="'.e($sizes).'"' : '');
    }

    /** Spaces in folder and file names ("Header orlena-01.png") must be encoded inside srcset. */
    private static function url(string $path): string
    {
        return str_replace(' ', '%20', $path);
    }
}

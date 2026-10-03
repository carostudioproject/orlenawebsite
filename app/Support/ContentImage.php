<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContentImage
{
    /** Upload rules shared by every content image field. */
    public const RULES = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'];

    /** Existing images must be site assets or earlier uploads; arbitrary URLs are rejected. */
    public const PATH = ['nullable', 'string', 'max:500', 'regex:#^/(assets|storage/content)/[^?\#]+\.(png|jpe?g|webp)$#i', 'not_regex:#\.\.#'];

    /** Longest side of stored uploads; photos are shown in cards and sliders, never larger than this. */
    private const MAX_SIDE = 1600;

    public static function resolve(?UploadedFile $upload, ?string $current): ?string
    {
        if (! $upload) {
            return $current;
        }

        return self::optimized($upload) ?? '/storage/'.$upload->store('content', 'public');
    }

    /**
     * Phone photos are often 5-10 MB. When GD can, store a WebP of at most MAX_SIDE pixels instead
     * (usually 100-300 KB); otherwise the original is kept.
     */
    private static function optimized(UploadedFile $upload): ?string
    {
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            return null;
        }
        $path = $upload->getRealPath();
        $info = @getimagesize($path);
        $image = match ($info[2] ?? null) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };
        if (! $image) {
            return null;
        }
        $image = self::orient($image, $path, $info[2]);
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        if ($scale < 1) {
            $resized = imagecreatetruecolor((int) round($width * $scale), (int) round($height * $scale));
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
            imagedestroy($image);
            $image = $resized;
        } else {
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        ob_start();
        $ok = imagewebp($image, null, 80);
        $data = (string) ob_get_clean();
        imagedestroy($image);
        if (! $ok || $data === '') {
            return null;
        }
        $name = 'content/'.Str::random(40).'.webp';
        Storage::disk('public')->put($name, $data);

        return '/storage/'.$name;
    }

    /** Phone JPEGs store rotation in EXIF; apply it so portrait photos are not sideways. */
    private static function orient(\GdImage $image, string $path, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }
        $angle = match ((int) (@exif_read_data($path)['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle ? (imagerotate($image, $angle, 0) ?: $image) : $image;
    }
}

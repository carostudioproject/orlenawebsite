<?php

namespace Tests\Feature;

use App\Support\ContentImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

class ContentImageTest extends TestCase
{
    #[RequiresPhpExtension('gd')]
    public function test_large_uploads_are_stored_as_small_webp(): void
    {
        Storage::fake('public');
        $path = ContentImage::resolve(UploadedFile::fake()->image('phone-photo.jpg', 4000, 3000), null);

        $this->assertMatchesRegularExpression('#^/storage/content/[A-Za-z0-9]{40}\.webp$#', $path);
        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get(substr($path, strlen('/storage/'))));
        $this->assertSame([1600, 1200], [$width, $height]);
    }

    public function test_without_an_upload_the_current_image_is_kept(): void
    {
        $this->assertSame('/assets/images/outlet1.jpg', ContentImage::resolve(null, '/assets/images/outlet1.jpg'));
    }
}

{{-- Meta Pixel: only where App\Support\MetaPixel allows it (public pages, and order pages when enabled). --}}
@if ($pixelId)
    <script src="/assets/js/meta-pixel.js?v=3" data-pixel-id="{{ $pixelId }}" defer></script>
    <noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id={{ $pixelId }}&amp;ev=PageView&amp;noscript=1"></noscript>
@endif

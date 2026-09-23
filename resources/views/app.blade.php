<!DOCTYPE html>
<html lang="en">
<head>
    @php
        $seo = $seo ?? ['title' => 'Orlena Staff', 'description' => 'Orlena staff dashboard', 'canonical' => url()->current(), 'image' => url('/assets/images/Orlena-Logo.png'), 'indexable' => false];
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}" inertia="description">
    <link rel="canonical" href="{{ $seo['canonical'] }}" inertia="canonical">
    <meta property="og:title" content="{{ $seo['title'] }}" inertia="og:title">
    <meta property="og:description" content="{{ $seo['description'] }}" inertia="og:description">
    <meta property="og:url" content="{{ $seo['canonical'] }}" inertia="og:url">
    <meta property="og:image" content="{{ $seo['image'] }}" inertia="og:image">
    <meta name="robots" content="{{ $seo['indexable'] ? 'index,follow' : 'noindex,nofollow' }}" inertia="robots">
    <link rel="icon" href="/favicon.ico">
    @vite('resources/js/app.ts')
    @inertiaHead
</head>
<body>
    @inertia
    @if (config('site.meta_pixel_enabled') && !request()->is('admin', 'admin/*', 'order', 'cart', 'checkout', 'orders/*', 'products', 'products/*'))
        <script src="/assets/js/meta-pixel.js" defer></script>
        <noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=787759320264256&amp;ev=PageView&amp;noscript=1"></noscript>
    @endif
</body>
</html>

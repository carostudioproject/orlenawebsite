<!DOCTYPE html>
<html lang="en">
<head>
    @php
        $seo = $seo ?? ['title' => 'Orlena Staff', 'description' => 'Orlena staff dashboard', 'canonical' => url()->current(), 'image' => url('/assets/images/Orlena-Logo.png'), 'indexable' => false];
        // Order pages share the public site's header and footer (rendered here, outside the Vue app); the dashboard has its own layout.
        $shop = ! request()->is('admin', 'admin/*');
        $site = $shop ? app(\App\Support\SiteContent::class)->get('social') : [];
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
    @vite($shop ? ['resources/js/app.ts', 'resources/js/site-chrome.ts'] : ['resources/js/app.ts'])
    @inertiaHead
</head>
<body>
    @if ($shop)
        <a href="#main-content" class="skip-link">Skip to content</a>
        @include('partials.site-header')
        <main id="main-content" tabindex="-1">@inertia</main>
        @include('partials.site-footer')
    @else
        @inertia
    @endif
</body>
</html>

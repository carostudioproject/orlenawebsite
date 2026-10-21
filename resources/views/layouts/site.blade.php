{{-- Public website shell. Pages render as complete HTML on the server (for search engines); resources/js/public.ts only adds sliders, the mobile menu and smooth section scrolling. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">
    <meta property="og:type" content="{{ $seo['type'] ?? 'website' }}">
    <meta property="og:site_name" content="Orlena">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta property="og:image" content="{{ $seo['image'] }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="robots" content="{{ $seo['indexable'] ? 'index,follow' : 'noindex,nofollow' }}">
    <link rel="icon" href="/favicon.ico">
    {{-- The page's main photo (hero banner, article cover) starts downloading before the CSS and scripts. --}}
    @stack('preload')
    @vite('resources/js/public.ts')
    @foreach ($structuredData ?? [] as $data)
        <script type="application/ld+json">{!! json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    @endforeach
</head>
<body>
    @include('partials.page-loader')
    <a href="#main-content" class="skip-link">Skip to content</a>
    @include('partials.site-header')
    <main id="main-content" tabindex="-1">@yield('content')</main>
    @include('partials.site-footer')
    @if ($site['whatsappNumber'] ?? null)
        <a href="https://wa.me/{{ $site['whatsappNumber'] }}" target="_blank" rel="noopener noreferrer" class="whatsapp-floating" aria-label="Chat with Orlena on WhatsApp">@include('partials.icon', ['name' => 'whatsapp'])</a>
    @endif
    @include('partials.meta-pixel', ['pixelId' => \App\Support\MetaPixel::id()])
</body>
</html>

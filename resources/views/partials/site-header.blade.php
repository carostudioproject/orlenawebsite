{{-- Shared by the public pages and the order pages. Pre-order ('/order') stays out of the menu until launch; the page is reachable by direct link.
     Social links are managed in the dashboard (Website content → Social media & WhatsApp). --}}
@php
    $menu = [['Home', '/'], ['About', '/about'], ['Baked Goods', '/#baked-goods'], ['Location', '/#outlet'], ['Collaboration', '/#collaboration']];
    $instagram = $site['instagramUrl'] ?? null;
    $tiktok = $site['tiktokUrl'] ?? null;
@endphp
<header class="header-navbar sticky top-0 z-40 shadow-sm">
    <nav class="container flex min-h-[66px] items-center justify-between py-2" aria-label="Main navigation">
        <a href="/" class="mr-4 py-[5px]"><img src="/assets/images/Orlena-Logo.png" alt="Orlena" class="navbar-logo"></a>
        <div class="hidden flex-1 items-center min-[992px]:flex">
            <ul class="m-0 flex flex-1 list-none justify-center gap-1 p-0">
                @foreach ($menu as [$label, $href])
                    <li><a href="{{ $href }}" class="nav-underline block px-2 py-2 text-inherit no-underline">{{ $label }}</a></li>
                @endforeach
            </ul>
            <div class="flex items-center gap-4">
                @if ($instagram)<a href="{{ $instagram }}" target="_blank" rel="noopener noreferrer" aria-label="Instagram" class="header-social"><i class="fab fa-instagram fa-lg" aria-hidden="true"></i></a>@endif
                @if ($tiktok)<a href="{{ $tiktok }}" target="_blank" rel="noopener noreferrer" aria-label="TikTok" class="header-social"><i class="fa-brands fa-tiktok" aria-hidden="true"></i></a>@endif
            </div>
        </div>
        <button type="button" class="or-mobile-toggle min-[992px]:!hidden" aria-expanded="false" aria-controls="mobile-menu" aria-label="Open menu" data-menu-open><span></span><span></span></button>
    </nav>
</header>
<dialog id="mobile-menu" class="header-offcanvas m-0 h-dvh max-h-none w-screen max-w-none border-0 p-0" aria-label="Main menu">
    <div class="or-mobile-header">
        <a href="/" class="or-mobile-logo" data-menu-close><img src="/assets/images/Orlena-Logo.png" alt="Orlena"></a>
        <button type="button" class="or-mobile-close" aria-label="Close menu" data-menu-close><span></span><span></span></button>
    </div>
    <div class="offcanvas-body">
        <nav class="menu-wrapper" aria-label="Mobile navigation">
            <ul class="navbar-nav list-none">
                @foreach ($menu as [$label, $href])
                    <li class="nav-item"><a href="{{ $href }}" class="nav-link" data-menu-close>{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>
        <div class="or-mobile-bottom">
            <a href="/blog" class="or-mobile-blog" data-menu-close>What's on Orlena</a>
            <div class="or-mobile-social">
                @if ($instagram)<a href="{{ $instagram }}" target="_blank" rel="noopener noreferrer">Instagram</a>@endif
                @if ($tiktok)<a href="{{ $tiktok }}" target="_blank" rel="noopener noreferrer">TikTok</a>@endif
            </div>
        </div>
    </div>
</dialog>

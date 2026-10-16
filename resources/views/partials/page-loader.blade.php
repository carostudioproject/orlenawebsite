{{-- Full-screen splash with the Orlena logo. Styles are inline so it paints before any CSS file loads.
     Scripts remove it when the page is ready (public.ts on load, app.ts once Vue mounts); without JS it never shows,
     and a CSS fallback fades it out after a few seconds in case a script fails. --}}
<style>
    #page-loader { position: fixed; inset: 0; z-index: 9999; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1.5rem; background: #fffaf6; transition: opacity .35s ease, visibility .35s ease; animation: page-loader-fallback .4s ease 8s forwards; }
    #page-loader img { width: min(52vw, 220px); height: auto; animation: page-loader-pulse 1.6s ease-in-out infinite; }
    #page-loader .page-loader-bar { width: min(40vw, 160px); height: 3px; border-radius: 999px; background: #3b030418; overflow: hidden; }
    #page-loader .page-loader-bar::after { content: ''; display: block; width: 40%; height: 100%; border-radius: inherit; background: #3b0304; animation: page-loader-slide 1.1s ease-in-out infinite; }
    #page-loader.is-hidden { opacity: 0; visibility: hidden; }
    @keyframes page-loader-pulse { 50% { opacity: .55; transform: scale(.97); } }
    @keyframes page-loader-slide { from { transform: translateX(-100%); } to { transform: translateX(250%); } }
    @keyframes page-loader-fallback { to { opacity: 0; visibility: hidden; } }
    @media (prefers-reduced-motion: reduce) { #page-loader img, #page-loader .page-loader-bar::after { animation: none; } }
</style>
<noscript><style>#page-loader { display: none !important; }</style></noscript>
<div id="page-loader" role="status" aria-live="polite">
    <img src="/assets/images/Orlena-Logo.png" alt="Orlena" width="534" height="201" fetchpriority="high">
    <span class="page-loader-bar" aria-hidden="true"></span>
    <span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">{{ $label ?? 'Memuat…' }}</span>
</div>

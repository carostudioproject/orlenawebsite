import { scrollToSection, smoothScrollTo } from '../Support/smoothScroll';

/** Removes #fragment from the address bar so section navigation keeps a clean URL. */
function clearHash() {
    if (window.location.hash) history.replaceState(history.state, '', window.location.pathname + window.location.search);
}

/**
 * Header, footer and menu links such as "/#outlet": on the homepage they glide to the section without a # in the URL;
 * from other pages the browser opens the homepage and the section is scrolled to after load.
 * Clicking a link to the page you are on (logo, Home) glides back to the top instead of reloading.
 */
export function initNavigation() {
    document.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = (event.target as Element | null)?.closest<HTMLAnchorElement>('a[href]');
        if (!link || link.target === '_blank' || link.hasAttribute('download') || link.classList.contains('skip-link')) return;
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || url.pathname !== window.location.pathname || url.search !== window.location.search) return;
        const id = decodeURIComponent(url.hash.slice(1));
        if (id && document.getElementById(id)) {
            event.preventDefault();
            clearHash();
            scrollToSection(id);
        } else if (!url.hash) {
            event.preventDefault();
            smoothScrollTo('top');
        }
    });

    // A hash typed or changed in the address bar while on the page.
    window.addEventListener('hashchange', () => {
        const id = decodeURIComponent(window.location.hash.slice(1));
        if (id && document.getElementById(id)) { clearHash(); scrollToSection(id); }
    });

    // Shared or bookmarked links such as /#outlet: scroll once the page has laid out, then tidy the URL.
    const id = decodeURIComponent(window.location.hash.slice(1));
    if (id && document.getElementById(id)) {
        requestAnimationFrame(() => { scrollToSection(id); clearHash(); });
    }
}

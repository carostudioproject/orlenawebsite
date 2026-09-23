import { router } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted } from 'vue';
import { scrollToSection, smoothScrollTo } from '../Support/smoothScroll';

// Public pages that can be swapped in place by Inertia; anything else (order form, admin, files) loads normally.
const inertiaPaths = /^\/(about|blog(\/[a-z0-9-]+)?)?$/;

const afterRender = () => new Promise<void>(resolve => nextTick(() => requestAnimationFrame(() => resolve())));

/** Removes #fragment from the address bar so section navigation keeps a clean URL. */
function clearHash() {
    if (window.location.hash) history.replaceState(history.state, '', window.location.pathname + window.location.search);
}

async function goToSection(id: string) {
    if (window.location.pathname !== '/') {
        router.visit('/', { onSuccess: async () => { await afterRender(); clearHash(); scrollToSection(id); } });
        return;
    }
    clearHash();
    scrollToSection(id);
}

// A hash typed or changed in the address bar while already on the homepage.
function onHashChange() {
    const id = decodeURIComponent(window.location.hash.slice(1));
    if (id && window.location.pathname === '/' && document.getElementById(id)) goToSection(id);
}

function onClick(event: MouseEvent) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = (event.target as Element | null)?.closest<HTMLAnchorElement>('a[href]');
    if (!link || link.target === '_blank' || link.hasAttribute('download') || link.classList.contains('skip-link')) return;
    const url = new URL(link.href, window.location.href);
    if (url.origin !== window.location.origin) return;

    const id = url.hash.slice(1);
    if (id && url.pathname === '/') {
        event.preventDefault();
        goToSection(decodeURIComponent(id));
    } else if (!url.hash && url.pathname === window.location.pathname && url.search === window.location.search) {
        // Clicking the page you are on (logo, Home) glides back to the top instead of reloading.
        event.preventDefault();
        smoothScrollTo('top');
    } else if (!url.hash && inertiaPaths.test(url.pathname)) {
        event.preventDefault();
        router.visit(url.pathname + url.search);
    }
}

export function usePublicNavigation() {
    let removeNavigate: (() => void) | undefined;
    onMounted(async () => {
        document.addEventListener('click', onClick);
        window.addEventListener('hashchange', onHashChange);
        // Shared or bookmarked links such as /#outlet still work, then the hash is tidied away.
        const id = window.location.hash.slice(1);
        if (id && window.location.pathname === '/') {
            await afterRender();
            scrollToSection(decodeURIComponent(id));
            // Inertia writes the initial URL (with hash) through a queued history update, so tidy up after it settles.
            setTimeout(clearHash, 150);
            window.addEventListener('load', () => setTimeout(clearHash, 0), { once: true });
        }
        // Fade the incoming page in after an in-place visit.
        removeNavigate = router.on('navigate', () => {
            const main = document.getElementById('main-content');
            main?.classList.remove('page-enter');
            void main?.offsetWidth;
            main?.classList.add('page-enter');
        });
    });
    onBeforeUnmount(() => {
        document.removeEventListener('click', onClick);
        window.removeEventListener('hashchange', onHashChange);
        removeNavigate?.();
    });
}

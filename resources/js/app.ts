import '../css/app.css';
import '@splidejs/splide/css';
import '@fortawesome/fontawesome-free/css/brands.min.css';
import '@fortawesome/fontawesome-free/css/solid.min.css';
import '@fortawesome/fontawesome-free/css/fontawesome.min.css';
import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { hidePageLoader } from './Support/pageLoader';

createInertiaApp({
    // One chunk per page: public visitors never download the dashboard pages.
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue', { import: 'default' });
        return pages[`./Pages/${name}.vue`]();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
        hidePageLoader();
    },
    progress: { color: '#3b0304', delay: 150 },
});

// Page changes that take a moment get a spinner overlay. Live filters, polling and partial reloads keep the page usable.
const overlay = document.createElement('div');
overlay.className = 'nav-loader';
overlay.setAttribute('role', 'status');
overlay.setAttribute('aria-live', 'polite');
overlay.innerHTML = '<img src="/assets/images/Orlena-Logo.png" alt="" class="nav-loader-logo"><span class="nav-loader-bar" aria-hidden="true"></span><span class="sr-only">Loading</span>';
document.body.appendChild(overlay);
let timer: number | undefined;
router.on('start', (event) => {
    const visit = event.detail.visit;
    if (visit.method !== 'get' || visit.preserveState || visit.only.length || visit.prefetch) return;
    timer = window.setTimeout(() => overlay.classList.add('is-visible'), 300);
});
router.on('finish', () => {
    window.clearTimeout(timer);
    overlay.classList.remove('is-visible');
});

import Splide, { type Options } from '@splidejs/splide';

const outlet: Options = {
    perPage: 4, gap: '1.25rem',
    breakpoints: { 1200: { perPage: 3, gap: '1rem' }, 992: { perPage: 2.5, gap: '1rem' }, 768: { perPage: 1.6, gap: '1rem' }, 576: { perPage: 1, fixedWidth: '84%', gap: '1rem' } },
};

// Only sliders present on the current page are mounted.
const sliders: Record<string, Options> = {
    'main-slider': { type: 'loop', perPage: 1, gap: '1rem', padding: '30rem', pagination: true, breakpoints: { 1600: { padding: '20rem' }, 1400: { padding: '20rem' }, 1200: { padding: '15rem' }, 992: { padding: '10rem' }, 768: { padding: '10rem' }, 576: { padding: '3rem' } } },
    'baked-goods-slider': { perPage: 4, gap: '1.25rem', breakpoints: { 1200: { perPage: 3, gap: '1rem' }, 992: { perPage: 2, gap: '1rem' }, 768: { perPage: 2, gap: '1rem' }, 576: { perPage: 1, fixedWidth: '78%', gap: '1rem', padding: { right: '22%' } } } },
    'outlet-slider': outlet,
    'about-outlet-slider': outlet,
    'collaboration-slider': { ...outlet, gap: '1.5rem', breakpoints: { ...outlet.breakpoints, 1200: { perPage: 3, gap: '1.25rem' }, 576: { perPage: 1, fixedWidth: '85%', gap: '1rem' } } },
    'blog-slider': { perPage: 3, gap: '1.5rem', breakpoints: { 1200: { perPage: 3, gap: '1.25rem' }, 992: { perPage: 2, gap: '1.25rem' }, 768: { perPage: 1, fixedWidth: '88%', gap: '1rem', padding: { right: '12%' } }, 576: { perPage: 1, fixedWidth: '88%', gap: '1rem', padding: { right: '12%' } } } },
};

export function mountSliders() {
    for (const [id, options] of Object.entries(sliders)) {
        const element = document.getElementById(id);
        if (!element) continue;
        element.setAttribute('aria-label', element.getAttribute('aria-label') ?? id.replaceAll('-', ' '));
        element.tabIndex = 0;
        new Splide(element, { type: 'slide', perMove: 1, arrows: false, pagination: false, drag: true, snap: true, speed: 400, flickPower: 300, flickMaxPages: 1, dragMinThreshold: { touch: 5, mouse: 0 }, keyboard: 'focused', ...options }).mount();
    }
}

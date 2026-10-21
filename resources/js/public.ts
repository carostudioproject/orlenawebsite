// Public website entry. Pages are complete HTML from the server; this only adds behaviour on top.
import '../css/public.css';
import '@splidejs/splide/css';
import { initMobileMenu } from './site/mobileMenu';
import { initNavigation } from './site/navigation';
import { mountSliders } from './site/sliders';
import { hidePageLoader } from './Support/pageLoader';

initMobileMenu();
initNavigation();
mountSliders();

// The splash only waits for the page's main photo (the first hero banner or article cover), not for every image,
// and never longer than 1.5 s, so the first screen shows as early as possible on slow networks.
const mainPhoto = document.querySelector<HTMLImageElement>('main img[fetchpriority="high"]');
if (!mainPhoto || mainPhoto.complete) hidePageLoader();
else {
    mainPhoto.addEventListener('load', hidePageLoader, { once: true });
    mainPhoto.addEventListener('error', hidePageLoader, { once: true });
    window.setTimeout(hidePageLoader, 1500);
}

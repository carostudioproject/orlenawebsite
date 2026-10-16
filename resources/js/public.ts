// Public website entry. Pages are complete HTML from the server; this only adds behaviour on top.
import '../css/public.css';
import '@splidejs/splide/css';
import '@fortawesome/fontawesome-free/css/brands.min.css';
import '@fortawesome/fontawesome-free/css/solid.min.css';
import '@fortawesome/fontawesome-free/css/fontawesome.min.css';
import { initMobileMenu } from './site/mobileMenu';
import { initNavigation } from './site/navigation';
import { mountSliders } from './site/sliders';
import { hidePageLoader } from './Support/pageLoader';

initMobileMenu();
initNavigation();
mountSliders();

// The splash stays until images are in, but never longer than 2.5 s so text is readable quickly on slow networks.
if (document.readyState === 'complete') hidePageLoader();
else {
    window.addEventListener('load', hidePageLoader, { once: true });
    window.setTimeout(hidePageLoader, 2500);
}

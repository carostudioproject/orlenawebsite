// Public website entry. Pages are complete HTML from the server; this only adds behaviour on top.
import '../css/public.css';
import '@splidejs/splide/css';
import '@fortawesome/fontawesome-free/css/brands.min.css';
import '@fortawesome/fontawesome-free/css/solid.min.css';
import '@fortawesome/fontawesome-free/css/fontawesome.min.css';
import { initMobileMenu } from './site/mobileMenu';
import { initNavigation } from './site/navigation';
import { mountSliders } from './site/sliders';

initMobileMenu();
initNavigation();
mountSliders();

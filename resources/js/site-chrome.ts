// Order pages (Inertia) share the server-rendered header and footer with the public site; this wires up their menu and section links.
import { initMobileMenu } from './site/mobileMenu';
import { initNavigation } from './site/navigation';

initMobileMenu();
initNavigation();

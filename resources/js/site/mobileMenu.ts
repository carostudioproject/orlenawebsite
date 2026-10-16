/** Full-screen mobile menu (<dialog id="mobile-menu">): Escape and every link close it; the page behind does not scroll while open. */
export function initMobileMenu() {
    const dialog = document.getElementById('mobile-menu') as HTMLDialogElement | null;
    const trigger = document.querySelector<HTMLButtonElement>('[data-menu-open]');
    if (!dialog || !trigger) return;

    const close = () => {
        if (!dialog.open) return;
        dialog.close();
        trigger.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        trigger.focus();
    };
    // showModal() focuses the first link (the logo), which iOS outlines; start focus on the dialog itself instead.
    dialog.tabIndex = -1;
    dialog.style.outline = 'none';
    trigger.addEventListener('click', () => {
        dialog.showModal();
        dialog.focus({ preventScroll: true });
        trigger.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    });
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    dialog.querySelectorAll('[data-menu-close]').forEach(element => element.addEventListener('click', close));
    window.addEventListener('resize', () => { if (window.innerWidth >= 992) close(); });
}

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
    trigger.addEventListener('click', () => {
        dialog.showModal();
        trigger.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    });
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    dialog.querySelectorAll('[data-menu-close]').forEach(element => element.addEventListener('click', close));
    window.addEventListener('resize', () => { if (window.innerWidth >= 992) close(); });
}

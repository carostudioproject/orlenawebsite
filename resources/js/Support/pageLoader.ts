// Hides the full-screen splash from resources/views/partials/page-loader.blade.php.
export function hidePageLoader(): void {
    const loader = document.getElementById('page-loader');
    if (!loader) return;
    loader.classList.add('is-hidden');
    window.setTimeout(() => loader.remove(), 400);
}

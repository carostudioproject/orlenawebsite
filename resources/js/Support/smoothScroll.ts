let frame = 0;
let cancelOnInput: (() => void) | null = null;

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const easeInOutCubic = (t: number) => (t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

function headerOffset(): number {
    const header = document.querySelector<HTMLElement>('.header-navbar');
    return (header?.getBoundingClientRect().height ?? 0) + 8;
}

function stop() {
    cancelAnimationFrame(frame);
    cancelOnInput?.();
    cancelOnInput = null;
}

/**
 * Eased scroll that re-reads the destination every frame, so images or sliders
 * that finish loading mid-animation do not leave the section under the header.
 */
export function smoothScrollTo(target: HTMLElement | 'top', onDone?: () => void) {
    stop();
    const destination = () => (target === 'top' ? 0 : Math.max(0, target.getBoundingClientRect().top + window.scrollY - headerOffset()));
    const start = window.scrollY;
    if (reducedMotion()) {
        window.scrollTo(0, destination());
        onDone?.();
        return;
    }
    const duration = Math.min(1100, Math.max(450, Math.abs(destination() - start) * .45));
    const began = performance.now();
    // Any manual scroll gesture takes over immediately instead of fighting the animation.
    const interrupt = () => stop();
    const events = ['wheel', 'touchstart', 'keydown'] as const;
    events.forEach(event => window.addEventListener(event, interrupt, { passive: true, once: true }));
    cancelOnInput = () => events.forEach(event => window.removeEventListener(event, interrupt));
    const step = (now: number) => {
        const progress = Math.min(1, (now - began) / duration);
        window.scrollTo(0, start + (destination() - start) * easeInOutCubic(progress));
        if (progress < 1) frame = requestAnimationFrame(step);
        else { stop(); onDone?.(); }
    };
    frame = requestAnimationFrame(step);
}

/** Scrolls to a section by id and moves keyboard focus there without a second jump. */
export function scrollToSection(id: string): boolean {
    const section = document.getElementById(id);
    if (!section) return false;
    smoothScrollTo(section, () => {
        if (!section.hasAttribute('tabindex')) section.setAttribute('tabindex', '-1');
        section.focus({ preventScroll: true });
    });
    return true;
}

import { usePage } from '@inertiajs/vue3';

/**
 * Meta Pixel events on the order pages, as the ads team asked: the submitted PO request (lead) here, Contact and
 * FindLocation in meta-pixel.js, Purchase from the server. Nothing happens unless the server loaded the Pixel on this
 * page (META_PIXEL_ORDER_PAGES); only amounts are sent, never the customer's details.
 */
declare global {
    interface Window { fbq?: (...args: unknown[]) => void }
}

export function track(event: string, params: Record<string, unknown> = {}, eventId?: string) {
    // The Pixel stays loaded after an in-app visit to the payment or tracking page; only pages the server marked send events.
    if (typeof window === 'undefined' || typeof window.fbq !== 'function' || !leadEvent()) return;
    if (eventId) window.fbq('track', event, params, { eventID: eventId });
    else window.fbq('track', event, params);
}

/** The event for a submitted PO request (Lead or SubmitApplication), or null when order pages are not tracked. */
export function leadEvent(): string | null {
    return (usePage().props.metaLeadEvent as string | null | undefined) ?? null;
}

/** Sends an event once per key in this browser session (e.g. the lead for one Order Code). */
export function trackOnce(key: string, event: string, params: Record<string, unknown>, eventId?: string) {
    try {
        if (sessionStorage.getItem(`meta:${key}`)) return;
        sessionStorage.setItem(`meta:${key}`, '1');
    } catch {
        // Storage blocked: sending twice is better than never.
    }
    track(event, params, eventId);
}

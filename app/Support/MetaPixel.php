<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Where the Meta Pixel may load. Public pages (home, about, blog) once it is enabled with an ID; the order form,
 * its confirmation and "Tambah pesanan" only with META_PIXEL_ORDER_PAGES. Payment, order tracking and dashboard
 * pages never load it.
 */
class MetaPixel
{
    public static function id(): ?string
    {
        $id = trim((string) config('services.meta.pixel_id'));

        return config('services.meta.enabled') && preg_match('/^\d{5,20}$/', $id) ? $id : null;
    }

    /** The Pixel ID for an order page that may be tracked, or null. */
    public static function forOrderPage(Request $request): ?string
    {
        if (! config('services.meta.order_pages')) {
            return null;
        }

        return $request->is('order', 'order/tambah', 'orders/*') ? self::id() : null;
    }

    public static function leadEvent(): string
    {
        return (string) config('services.meta.lead_event', 'Lead');
    }
}

<?php

namespace App\Support;

/**
 * Tables moved from one server to another at go-live: the catalog, website content and settings.
 * Orders, customers, payments, users and logs never move (test data stays on the old server).
 * Staff references (updated_by) are cleared because user IDs differ between servers.
 */
final class CatalogTransfer
{
    /** In insert order (parents first). */
    public const TABLES = ['categories', 'outlets', 'products', 'outlet_prices', 'product_contents', 'posts', 'content_entries', 'settings', 'closed_dates'];

    /** Columns pointing at users, cleared on export. */
    public const USER_COLUMNS = ['updated_by'];

    public const VERSION = 1;
}

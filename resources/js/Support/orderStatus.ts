// Dashboard labels (English, like the public website).
export const orderStatusLabels: Record<string, string> = {
    pending_review: 'Awaiting review', confirmed: 'Confirmed', processing: 'Processing', ready: 'Ready',
    delivering: 'Out for delivery', completed: 'Completed', cancelled: 'Cancelled',
};

export const paymentStatusLabels: Record<string, string> = {
    not_created: 'No payment yet', creating: 'Creating link', creation_failed: 'Link failed', pending: 'Awaiting payment',
    paid: 'Paid', failed: 'Payment failed', expired: 'Link expired', cancelled: 'Link cancelled', refunded: 'Refunded',
};

export const syncStatusLabels: Record<string, string> = {
    pending: 'Queued', synced: 'Sent', failed: 'Failed', waiting_config: 'Awaiting setup', needs_mapping: 'Needs mapping', skipped: 'Skipped',
};
export const syncTypeLabels: Record<string, string> = { 'transaction.push': 'Send transaction', 'transaction.cancel': 'Cancel transaction' };

export const orderStatusLabel = (status: string | null) => (status ? orderStatusLabels[status] ?? status : '—');
export const paymentStatusLabel = (status: string) => paymentStatusLabels[status] ?? status;

// Customer order pages keep their Indonesian wording.
const customerOrderStatus: Record<string, string> = {
    pending_review: 'Menunggu pemeriksaan staff', confirmed: 'Terkonfirmasi', processing: 'Diproses', ready: 'Siap',
    delivering: 'Dikirim', completed: 'Selesai', cancelled: 'Dibatalkan',
};
const customerPaymentStatus: Record<string, string> = {
    not_created: 'Pembayaran belum dibuat', creating: 'Link sedang dibuat', creation_failed: 'Link gagal dibuat', pending: 'Menunggu pembayaran',
    paid: 'Lunas', failed: 'Pembayaran gagal', expired: 'Link kedaluwarsa', cancelled: 'Link dibatalkan', refunded: 'Dana dikembalikan',
};
export const customerOrderStatusLabel = (status: string) => customerOrderStatus[status] ?? status;
export const customerPaymentStatusLabel = (status: string) => customerPaymentStatus[status] ?? status;

/** Formats a server timestamp in WITA, the timezone used for all Orlena schedules. */
export function witaTime(value: string | null): string {
    if (!value) return '—';
    return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Makassar' }).format(new Date(value)) + ' WITA';
}

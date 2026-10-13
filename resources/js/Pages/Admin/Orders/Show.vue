<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import OrderSummary from '../../../Components/OrderSummary.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Field from '../../../Components/Field.vue';
import Pagination from '../../../Components/Admin/Pagination.vue';
import StatusBadge from '../../../Components/Admin/StatusBadge.vue';
import type { AdminProps } from '../../../Types/admin';
import { rupiah } from '../../../Support/money';
import { alertDialog, confirmDialog } from '../../../Support/dialog';
import { orderStatusLabel, paymentStatusLabel, syncTypeLabels, witaTime } from '../../../Support/orderStatus';
import type { Paginated } from '../../../Types/admin';
import type { PaymentAttempt, Preorder, StatusChange } from '../../../Types/ordering';
defineOptions({ layout: AdminLayout });
interface Review { id: number; actor_name: string | null; note: string; previous_delivery_fee: number | null; delivery_fee: number | null; previous_schedule: string | null; schedule: string | null; created_at: string }
interface Addition { id: number; items: { name: string; variant: string | null; quantity: number; subtotal: number }[]; subtotal_added: number; previous_total: number; new_total: number; created_at: string }
const props = defineProps<{ order: Preorder; reviews: Paginated<Review>; history: StatusChange[]; payments: PaymentAttempt[]; canCancelPaid: boolean; additions: Addition[]; received: number; dokuEnabled: boolean; paymentMode: string; customerChooses: boolean; manualMethods: Record<string, string>;
    dayLoad: { orders: number; capacity: number | null; closed: string | null }; outlets: { id: number; name: string; delivers: boolean }[];
    erzapSync: { id: number; type: string; status: string; attempts: number; last_error: string | null; synced_at: string | null; next_attempt_at: string | null; external_ref: string | null } | null;
}>();
const page = usePage<AdminProps>();
const poCutoff = computed(() => page.props.poCutoff);
const can = computed(() => page.props.auth.can);
const errors = computed(() => page.props.errors as Record<string, string>);

const current = computed(() => props.payments[0] ?? null);
const openPayment = computed(() => props.payments.some(p => p.status === 'pending' || p.status === 'creating'));
const editable = computed(() => ['pending_review', 'confirmed'].includes(props.order.order_status)
    && ['not_created', 'failed', 'expired', 'cancelled'].includes(props.order.payment_status) && !openPayment.value);
const finished = computed(() => ['completed', 'cancelled'].includes(props.order.order_status));
const paidAfterCancel = computed(() => props.order.order_status === 'cancelled' && props.order.payment_status === 'paid');
// Money received on every attempt against the current total (an older link may have been paid after the total changed).
const shortfall = computed(() => (props.received > 0 && props.order.payment_status !== 'paid' && props.order.order_status !== 'cancelled' ? props.order.total - props.received : 0));
const overpaid = computed(() => (props.received > props.order.total ? props.received - props.order.total : 0));

const form = useForm({
    review_version: props.order.review_version, delivery_fee: props.order.delivery_fee ?? '' as number | string, note: '',
    requested_date: props.order.requested_date, requested_time: props.order.requested_time?.slice(0, 5) ?? '', outlet_id: props.order.outlet_id,
});
watch(() => props.order.review_version, () => {
    form.review_version = props.order.review_version; form.delivery_fee = props.order.delivery_fee ?? ''; form.outlet_id = props.order.outlet_id;
    form.requested_date = props.order.requested_date; form.requested_time = props.order.requested_time?.slice(0, 5) ?? '';
});
const feeLabel = (fee: number | null) => fee === null ? 'Not set' : rupiah(Number(fee));
function saveReview() {
    form.transform(data => ({ ...data, delivery_fee: data.delivery_fee === '' ? null : Number(data.delivery_fee), requested_time: data.requested_time || null }))
        .post(`/admin/orders/${props.order.id}/review`, { preserveScroll: true, onSuccess: () => form.reset('note') });
}
function reloadReview() { router.reload({ onSuccess: () => form.clearErrors() }); }

const busy = ref(false);
function act(url: string, data: Record<string, unknown> = {}, onSuccess?: () => void) {
    router.post(`/admin/orders/${props.order.id}${url}`, data as never, { preserveScroll: true, onStart: () => { busy.value = true; }, onFinish: () => { busy.value = false; }, onSuccess });
}
const linkKind = computed(() => ({ va: 'DOKU virtual account (VA) payment link', qris: 'QRIS payment page', direct: 'payment page (customer chooses VA BCA or QRIS)', demo: 'demo payment page', checkout: 'DOKU payment link' } as Record<string, string>)[props.paymentMode] ?? 'payment link');
const confirmPaymentLabel = computed(() => (props.customerChooses ? 'Confirm order' : props.paymentMode === 'va' ? 'Confirm & Create VA Payment' : props.paymentMode === 'qris' ? 'Confirm & Create QRIS' : 'Confirm & Create Payment'));
const methodLabel = (method?: string | null) => (method ? ({ va_bca: 'VA BCA', qris: 'QRIS' } as Record<string, string>)[method] ?? null : null);
async function confirmOrder() {
    const ok = await confirmDialog({
        title: 'Confirm this order?', icon: 'fa-circle-check', confirmLabel: props.dokuEnabled ? confirmPaymentLabel.value : 'Confirm order',
        message: props.dokuEnabled
            ? `A ${linkKind.value} for ${rupiah(props.order.total)} will be created for ${props.order.order_code}. Make sure the products, schedule and delivery fee are correct.`
            : `${props.order.order_code} will be confirmed for ${rupiah(props.order.total)}. Online payment is off: ask the customer to pay by transfer, then mark the order as paid.`,
    });
    if (ok) act('/confirm', { review_version: props.order.review_version });
}
// Payments received outside DOKU (transfer, cash). Also the only way to pay while DOKU is off.
const manualMethod = ref('transfer');
const manualNote = ref('');
const canMarkPaid = computed(() => can.value.review && props.order.order_status === 'confirmed' && !['paid', 'refunded'].includes(props.order.payment_status) && !openPayment.value);
async function markPaid() {
    const ok = await confirmDialog({
        title: `Mark ${props.order.order_code} as paid?`, icon: 'fa-money-bill-wave', confirmLabel: 'Mark as paid',
        message: `Only do this after ${rupiah(props.order.total)} has arrived (${props.manualMethods[manualMethod.value]}). The order becomes paid and is sent to Erzap.`,
    });
    if (ok) act('/payments/manual', { method: manualMethod.value, note: manualNote.value }, () => { manualNote.value = ''; });
}
const renewReason = ref('');
function renew() { act('/payments/renew', { reason: renewReason.value }, () => { renewReason.value = ''; }); }

const nextSteps = computed(() => {
    const delivery = props.order.fulfillment_method === 'delivery';
    const map: Record<string, { to: string; label: string }[]> = {
        confirmed: [{ to: 'processing', label: 'Start processing' }],
        processing: [{ to: 'ready', label: 'Mark as ready' }],
        ready: [delivery ? { to: 'delivering', label: 'Mark as out for delivery' } : { to: 'completed', label: 'Mark as completed (picked up)' }],
        delivering: [{ to: 'completed', label: 'Mark as completed (delivered)' }],
    };
    return map[props.order.order_status] ?? [];
});
function advance(to: string) { act('/status', { from: props.order.order_status, to }); }
const cancelReason = ref('');
const canCancel = computed(() => can.value.review && !finished.value && (props.order.payment_status !== 'paid' || props.canCancelPaid));
async function cancelOrder() {
    const ok = await confirmDialog({
        title: 'Cancel this order?', tone: 'danger', confirmLabel: 'Yes, cancel it', cancelLabel: 'Go back',
        message: props.order.payment_status === 'paid' ? 'This order is paid; the refund is done manually in DOKU. This cannot be undone.' : 'Any open payment link will be closed. This cannot be undone.',
    });
    if (ok) act('/cancel', { from: props.order.order_status, cancel_reason: cancelReason.value }, () => { cancelReason.value = ''; });
}

const copied = ref(false);
async function copyLink(url: string) {
    try { await navigator.clipboard.writeText(url); copied.value = true; setTimeout(() => { copied.value = false; }, 2000); } catch { alertDialog({ title: 'Copy payment link', icon: 'fa-link', message: 'Your browser blocked automatic copying. Copy the link below manually.', copyText: url }); }
}
// Customer chooses VA BCA or QRIS: no link exists yet, staff send our payment page until the deadline.
const choicePage = computed(() => (props.order.order_status === 'confirmed' && props.order.payment_status === 'not_created' && props.order.payment_due_at && !openPayment.value
    ? { url: `${window.location.origin}/bayar/${props.order.order_code}`, expired: new Date(props.order.payment_due_at).getTime() < Date.now() } : null));
const whatsappLink = computed(() => {
    const payment = current.value?.status === 'pending' && current.value.payment_url ? { url: current.value.payment_url, expires: current.value.expires_at }
        : choicePage.value && !choicePage.value.expired ? { url: choicePage.value.url, expires: props.order.payment_due_at ?? null } : null;
    if (!payment) return null;
    const text = `Halo ${props.order.customer.name}, pesanan ${props.order.order_code} sudah kami konfirmasi.\n\nTotal: ${rupiah(props.order.total)}\nLink pembayaran: ${payment.url}\nBerlaku sampai: ${witaTime(payment.expires)}\n\nCek status pesanan kapan saja: ${window.location.origin}/cek-pesanan?code=${props.order.order_code}\n\nTerima kasih.`;
    return `https://wa.me/${props.order.customer.whatsapp}?text=${encodeURIComponent(text)}`;
});

const waitingMinutes = computed(() => Math.floor((Date.now() - new Date(props.order.created_at).getTime()) / 60000));
// Paid after the H-1 cutoff: the server moved the date; staff tell the customer (no WhatsApp API).
const idDate = (date: string) => new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
const movedLink = computed(() => {
    if (!props.order.moved_from_date) return null;
    const what = props.order.fulfillment_method === 'delivery' ? 'pengiriman' : 'pengambilan';
    const time = props.order.requested_time ? `, jam ${props.order.requested_time.slice(0, 5).replace(':', '.')} WITA (tetap)` : '';
    const text = `Halo ${props.order.customer.name}, pembayaran pesanan ${props.order.order_code} sudah kami terima. Terima kasih!\n\nKarena pembayaran masuk setelah batas produksi (${poCutoff.value.replace('D-1 at', 'H-1 pukul').replace(':', '.')}), jadwal ${what} kami pindahkan dari ${idDate(props.order.moved_from_date)} ke *${idDate(props.order.requested_date)}*${time}.\n\nCek status pesanan: ${window.location.origin}/cek-pesanan?code=${props.order.order_code}`;
    return `https://wa.me/${props.order.customer.whatsapp}?text=${encodeURIComponent(text)}`;
});

// Webhooks update MySQL; polling keeps the open page current without a WebSocket server.
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => {
        if (document.hidden || busy.value || form.processing || (finished.value && !openPayment.value)) return;
        router.reload({ only: ['order', 'payments', 'history', 'additions', 'dayLoad', 'erzapSync'] });
    }, 15000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>
<template>
    <Head :title="`${order.order_code} · Orlena`" /><Link href="/admin/orders" class="admin-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to orders</Link><h1 class="my-5 break-all text-3xl font-bold">{{ order.order_code }}</h1>
    <div class="mb-6 flex flex-wrap gap-2"><StatusBadge kind="order" :status="order.order_status" /><StatusBadge kind="payment" :status="order.payment_status" /></div>
    <p v-if="order.order_status === 'pending_review' && waitingMinutes > 60" role="alert" class="admin-alert admin-alert-error mb-6"><i class="fa-solid fa-stopwatch" aria-hidden="true"></i> Waiting for review for {{ waitingMinutes >= 120 ? `${Math.floor(waitingMinutes / 60)} hours` : `${waitingMinutes} minutes` }}. Orders should be confirmed within 1 hour.</p>
    <p v-if="!can.review" class="admin-alert admin-alert-info mb-6">Read-only mode. Your account can view orders and payments but cannot change them.</p>
    <p v-if="['pending_review', 'confirmed'].includes(order.order_status) && (dayLoad.closed || (dayLoad.capacity && dayLoad.orders > dayLoad.capacity))" class="admin-alert admin-alert-error mb-6"><i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i> {{ dayLoad.closed ?? `${order.outlet_name_snapshot} is over its daily quota on this date: ${dayLoad.orders} orders for a quota of ${dayLoad.capacity}.` }} Make sure production can handle it or agree on another date with the customer.</p>
    <p v-else-if="order.order_status === 'pending_review' && dayLoad.capacity" class="admin-muted mb-4 text-sm"><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> {{ dayLoad.orders }} of {{ dayLoad.capacity }} orders at {{ order.outlet_name_snapshot }} on this PO date (daily quota).</p>
    <p v-if="additions.length && order.order_status === 'pending_review'" class="admin-alert admin-alert-info mb-6"><i class="fa-solid fa-cart-plus" aria-hidden="true"></i> The customer added items {{ additions.length }} time(s), last on {{ witaTime(additions[0].created_at) }}. Items and total below include the additions; check availability again before confirming.</p>
    <div v-if="order.moved_from_date && order.order_status !== 'cancelled'" class="admin-alert admin-alert-info mb-6 flex flex-wrap items-center justify-between gap-3">
        <p class="m-0"><i class="fa-solid fa-calendar-plus" aria-hidden="true"></i> Paid after the cut-off for <strong>{{ order.moved_from_date }}</strong>, so the order moved to <strong>{{ order.requested_date }}</strong> (same time). Let the customer know.</p>
        <a v-if="movedLink" :href="movedLink" target="_blank" rel="noopener noreferrer" class="admin-secondary"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i>Tell the customer</a>
    </div>
    <p v-if="order.nearest_outlet && ['pending_review', 'confirmed'].includes(order.order_status)" class="admin-alert admin-alert-info mb-6"><i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i> The customer asked Orlena to choose the <strong>nearest outlet</strong>. It is set to {{ order.outlet_name_snapshot }} for now; check the location and change the outlet in the review form if needed.</p>
    <p v-if="paidAfterCancel" role="alert" class="admin-alert admin-alert-error mb-6">This order was cancelled but payment was received. Refund it manually in DOKU and record the result.</p>
    <p v-if="shortfall > 0" role="alert" class="admin-alert admin-alert-error mb-6"><i class="fa-solid fa-scale-unbalanced" aria-hidden="true"></i> Received {{ rupiah(received) }} of {{ rupiah(order.total) }}: an older payment link was paid after the total changed. Collect the remaining {{ rupiah(shortfall) }} (Mark as paid records only the difference) or adjust the order.</p>
    <p v-if="overpaid > 0" role="alert" class="admin-alert admin-alert-error mb-6"><i class="fa-solid fa-scale-unbalanced" aria-hidden="true"></i> Received {{ rupiah(received) }}, which is {{ rupiah(overpaid) }} more than the total {{ rupiah(order.total) }}. Refund the difference to the customer.</p>
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2"><section class="admin-card"><h2 class="mb-5 text-xl">Customer and fulfillment</h2><dl class="space-y-4 text-sm"><div><dt class="font-bold">Name</dt><dd>{{ order.customer.name }}</dd></div><div><dt class="font-bold">WhatsApp</dt><dd>{{ order.customer.whatsapp }}</dd></div><div v-if="order.customer.email"><dt class="font-bold">Email</dt><dd>{{ order.customer.email }}</dd></div><div><dt class="font-bold">Fulfillment</dt><dd>{{ order.fulfillment_method === 'delivery' ? `Delivery (Gojek/Grab) from ${order.outlet_name_snapshot}` : `Pickup at ${order.outlet_name_snapshot}` }}</dd></div><div><dt class="font-bold">Requested date / time</dt><dd>{{ order.requested_date }} {{ order.requested_time?.slice(0,5) }} WITA</dd></div><div v-if="order.delivery_address"><dt class="font-bold">Address</dt><dd class="whitespace-pre-wrap">{{ order.delivery_address }}</dd></div><div v-if="order.location_url"><dt class="font-bold">Location</dt><dd><a :href="order.location_url" target="_blank" rel="noopener noreferrer" class="break-all underline"><i class="fa-solid fa-map-location-dot" aria-hidden="true"></i> Open in Google Maps</a></dd></div><div v-if="order.recipient_whatsapp"><dt class="font-bold">Recipient WhatsApp</dt><dd><a :href="`https://wa.me/${order.recipient_whatsapp}`" target="_blank" rel="noopener noreferrer" class="underline">{{ order.recipient_whatsapp }}</a></dd></div><div v-if="order.customer_note"><dt class="font-bold">Customer note</dt><dd class="whitespace-pre-wrap">{{ order.customer_note }}</dd></div><div v-if="order.card_message"><dt class="font-bold"><i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i> Greeting card</dt><dd class="whitespace-pre-wrap rounded-lg bg-cream p-3">{{ order.card_message }}</dd></div></dl></section><section class="admin-card"><h2 class="mb-5 text-xl">Order items</h2><OrderSummary :order="order" lang="en" /></section></div>

    <section class="admin-card mt-6">
        <h2 class="mb-4 text-xl">Payment</h2>
        <p v-if="errors.payment" role="alert" class="admin-alert admin-alert-error mb-4">{{ errors.payment }}</p>
        <div v-if="can.review && order.order_status === 'pending_review'" class="space-y-3 text-sm">
            <p v-if="dokuEnabled">Check products, production capacity, schedule and delivery fee. Confirming creates a {{ linkKind }} for <strong>{{ rupiah(order.total) }}</strong>, valid for 2 hours (until {{ $page.props.poPaymentClose }} at the latest; still unpaid then, the order is cancelled automatically).</p>
            <p v-else>Check products, production capacity, schedule and delivery fee, then confirm. <strong>Online payment (DOKU) is off</strong>: ask the customer to pay {{ rupiah(order.total) }} by transfer and mark the order as paid when it arrives.</p>
            <p v-if="order.delivery_fee === null" class="admin-error-text">Set the delivery fee in the review form before confirming.</p>
            <button class="admin-primary" :disabled="busy || order.delivery_fee === null" @click="confirmOrder"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>{{ busy ? 'Processing…' : dokuEnabled ? confirmPaymentLabel : 'Confirm order' }}</button>
        </div>
        <div v-if="choicePage" class="space-y-3 text-sm" :class="{ 'mb-5 border-b border-chocolate/10 pb-5': current }">
            <p class="m-0"><strong>{{ choicePage.expired ? 'Payment deadline passed' : 'Waiting for the customer to choose VA BCA or QRIS' }}</strong> · deadline {{ witaTime(order.payment_due_at ?? null) }}</p>
            <div v-if="!choicePage.expired" class="flex flex-wrap gap-3">
                <button type="button" class="admin-primary" @click="copyLink(choicePage.url)"><i class="fa-solid fa-copy" aria-hidden="true"></i>{{ copied ? 'Link copied' : 'Copy payment link' }}</button>
                <a v-if="whatsappLink" :href="whatsappLink" target="_blank" rel="noopener noreferrer" class="admin-secondary"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i>Send via WhatsApp</a>
                <a :href="choicePage.url" target="_blank" rel="noopener noreferrer" class="admin-secondary"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>Open payment page</a>
            </div>
            <p class="admin-muted m-0 text-xs">The VA number or QR is created when the customer chooses. This page refreshes every 15 seconds.</p>
        </div>
        <div v-if="current" class="space-y-3 text-sm" :class="{ 'mt-5 border-t border-chocolate/10 pt-5': order.order_status === 'pending_review' }">
            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3"><div><dt class="font-bold">{{ current.provider === 'manual' ? 'Manual payment' : 'Link' }} #{{ current.attempt }}<template v-if="methodLabel(current.method)"> ({{ methodLabel(current.method) }})</template> status</dt><dd>{{ paymentStatusLabel(current.status) }}</dd></div><div><dt class="font-bold">Amount</dt><dd>{{ rupiah(current.amount) }}</dd></div><div><dt class="font-bold">{{ current.status === 'paid' ? 'Paid at' : 'Valid until' }}</dt><dd>{{ witaTime(current.status === 'paid' ? current.paid_at : current.expires_at) }}</dd></div></dl>
            <div v-if="current.status === 'pending' && current.payment_url" class="flex flex-wrap gap-3">
                <button type="button" class="admin-primary" @click="copyLink(current.payment_url)"><i class="fa-solid fa-copy" aria-hidden="true"></i>{{ copied ? 'Link copied' : 'Copy payment link' }}</button>
                <a v-if="whatsappLink" :href="whatsappLink" target="_blank" rel="noopener noreferrer" class="admin-secondary"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i>Send via WhatsApp</a>
                <a :href="current.payment_url" target="_blank" rel="noopener noreferrer" class="admin-secondary"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>Open payment page</a>
            </div>
            <p v-if="current.status === 'creation_failed' && current.last_error" class="admin-error-text">{{ current.last_error }}</p>
            <button v-if="!['manual', 'demo'].includes(current.provider ?? '') && can.review && ['pending', 'expired', 'failed', 'cancelled'].includes(current.status)" type="button" class="admin-secondary" :disabled="busy" @click="act(`/payments/${current.id}/check`)"><i class="fa-solid fa-rotate" aria-hidden="true"></i>Check status in DOKU</button>
            <p v-if="current.status === 'pending'" class="text-xs text-chocolate/65">The status updates automatically when DOKU sends a notification. This page refreshes every 15 seconds.</p>
        </div>
        <form v-if="canMarkPaid" class="mt-5 space-y-3 rounded-xl border border-chocolate/15 p-4 text-sm" @submit.prevent="markPaid">
            <p class="m-0 font-bold"><i class="fa-solid fa-money-bill-wave" aria-hidden="true"></i> Record a payment received outside DOKU</p>
            <p class="admin-muted m-0 text-xs">Use this after the customer paid {{ rupiah(order.total) }} by transfer or at the outlet. The order becomes paid and is sent to Erzap.</p>
            <div class="flex flex-wrap items-end gap-3">
                <div><label for="manual_method" class="mb-1 block text-xs font-bold">Method</label><select id="manual_method" v-model="manualMethod"><option v-for="(label, value) in manualMethods" :key="value" :value="value">{{ label }}</option></select></div>
                <div class="min-w-48 flex-1"><label for="manual_note" class="mb-1 block text-xs font-bold">Note (optional)</label><input id="manual_note" v-model="manualNote" maxlength="500" placeholder="E.g. BCA transfer from Sinta, 3 Oct 10:15"></div>
                <button class="admin-primary" :disabled="busy"><i class="fa-solid fa-check" aria-hidden="true"></i>Mark as paid</button>
            </div>
        </form>
        <div v-if="dokuEnabled && !customerChooses && can.review && order.order_status === 'confirmed' && order.payment_status === 'not_created' && !openPayment" class="mt-4">
            <button type="button" class="admin-primary" :disabled="busy" @click="act('/payments/retry')"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i>Try creating the link again</button>
        </div>
        <form v-if="dokuEnabled && can.review && order.order_status === 'confirmed' && (['failed', 'expired', 'cancelled'].includes(order.payment_status) || choicePage?.expired) && !openPayment" class="mt-5 space-y-3" @submit.prevent="renew">
            <Field id="renew_reason" label="Reason for a new link" :error="errors.reason"><template #default="{ describedBy }"><textarea id="renew_reason" v-model="renewReason" required maxlength="500" rows="2" :aria-describedby="describedBy" :disabled="busy" placeholder="E.g. the customer asked for a new link after it expired"></textarea></template></Field>
            <p class="text-xs text-chocolate/65">Update the delivery fee and schedule in the review form first if anything changed. The new link uses the latest total.</p>
            <button class="admin-primary" :disabled="busy"><i class="fa-solid fa-link" aria-hidden="true"></i>Create new payment link</button>
        </form>
        <p v-if="!current && order.order_status !== 'pending_review'" class="text-sm text-chocolate/65">No payment link yet.</p>
        <details v-if="payments.length > 1" class="mt-5 text-sm"><summary class="cursor-pointer font-bold">Link history ({{ payments.length }})</summary><ol class="mt-3 space-y-3"><li v-for="payment in payments" :key="payment.id" class="border-b border-chocolate/10 pb-3"><p class="font-bold">#{{ payment.attempt }} · {{ paymentStatusLabel(payment.status) }} · {{ rupiah(payment.amount) }}</p><p class="text-xs text-chocolate/65">Created {{ witaTime(payment.created_at) }} by {{ payment.creator?.name ?? 'unknown account' }}</p><p v-if="payment.reason" class="whitespace-pre-wrap">Reason: {{ payment.reason }}</p><p v-if="payment.last_error" class="admin-error-text">{{ payment.last_error }}</p></li></ol></details>
    </section>

    <section class="admin-card mt-6">
        <h2 class="mb-4 text-xl">Order status</h2>
        <p v-if="errors.status" role="alert" class="admin-alert admin-alert-error mb-4">{{ errors.status }}</p>
        <div v-if="can.review && nextSteps.length" class="flex flex-wrap gap-3"><button v-for="step in nextSteps" :key="step.to" type="button" class="admin-primary" :disabled="busy || (step.to === 'processing' && order.payment_status !== 'paid')" @click="advance(step.to)"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i>{{ step.label }}</button></div>
        <p v-if="order.order_status === 'confirmed' && order.payment_status !== 'paid'" class="mt-3 text-sm text-chocolate/65">The order can be processed once it is paid.</p>
        <p v-if="finished" class="text-sm">Order {{ orderStatusLabel(order.order_status).toLowerCase() }}.</p>
        <details v-if="canCancel" class="mt-5 text-sm"><summary class="admin-error-text cursor-pointer font-bold">Cancel order</summary>
            <form class="mt-3 space-y-3" @submit.prevent="cancelOrder">
                <Field id="cancel_reason" label="Cancellation reason" :error="errors.cancel_reason"><template #default="{ describedBy }"><textarea id="cancel_reason" v-model="cancelReason" required maxlength="1000" rows="2" :aria-describedby="describedBy" :disabled="busy"></textarea></template></Field>
                <p v-if="order.payment_status === 'paid'" class="admin-error-text">This order is paid. The refund is done manually in DOKU.</p>
                <p v-else-if="openPayment" class="text-chocolate/65">The open payment link will be closed.</p>
                <button class="admin-secondary admin-danger" :disabled="busy"><i class="fa-solid fa-ban" aria-hidden="true"></i>Cancel order</button>
            </form>
        </details>
        <p v-else-if="can.review && !finished" class="mt-4 text-xs text-chocolate/65">Paid orders can only be cancelled by an Admin.</p>
    </section>

    <section v-if="can.review" class="admin-card mt-6">
        <h2 class="mb-4 text-xl">Staff review</h2>
        <form v-if="editable" class="space-y-5" @submit.prevent="saveReview">
            <div v-if="form.hasErrors" role="alert" class="admin-alert admin-alert-error"><p v-for="(error, key) in form.errors" :key="key">{{ error }}</p><button type="button" class="underline" :disabled="form.processing" @click="reloadReview">Reload order data</button></div>
            <Field v-if="order.fulfillment_method === 'delivery'" id="delivery_fee" label="Gojek/Grab delivery fee (Rp)" :error="form.errors.delivery_fee"><template #default="{ describedBy }"><input id="delivery_fee" v-model="form.delivery_fee" type="number" min="0" max="999999999" step="1" placeholder="Not set" :aria-describedby="describedBy" :disabled="form.processing"></template></Field>
            <p class="text-sm text-chocolate/65">{{ order.fulfillment_method === 'delivery' ? 'Leave empty if not decided yet. Enter 0 only for free delivery.' : 'Pickup has no delivery fee.' }}</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field id="review_outlet" class="sm:col-span-2" :label="order.fulfillment_method === 'delivery' ? 'Ships from outlet' : 'Pickup outlet'" :hint="order.nearest_outlet ? 'The customer asked for the nearest outlet: check the address and location link, then choose it here. Item prices stay as quoted.' : 'Item prices stay as quoted when the outlet changes.'" :error="form.errors.outlet_id"><template #default="{ describedBy }"><select id="review_outlet" v-model="form.outlet_id" :aria-describedby="describedBy" :disabled="form.processing"><option v-for="outlet in outlets.filter(item => order.fulfillment_method !== 'delivery' || item.delivers || item.id === order.outlet_id)" :key="outlet.id" :value="outlet.id">{{ outlet.name }}</option></select></template></Field>
                <Field id="requested_date" label="PO date" :error="form.errors.requested_date"><template #default="{ describedBy }"><input id="requested_date" v-model="form.requested_date" type="date" required :aria-describedby="describedBy" :disabled="form.processing"></template></Field>
                <Field id="requested_time" label="Time (optional)" :error="form.errors.requested_time"><template #default="{ describedBy }"><input id="requested_time" v-model="form.requested_time" type="time" :aria-describedby="describedBy" :disabled="form.processing"></template></Field>
            </div>
            <p class="text-xs text-chocolate/65">Change the schedule only after agreeing with the customer. The new date follows the order deadline ({{ $page.props.poCutoff }}), the closed dates in PO schedule and the outlet's daily and hourly quota.</p>
            <Field id="review_note" label="Internal review note" :error="form.errors.note"><template #default="{ describedBy }"><textarea id="review_note" v-model="form.note" required maxlength="2000" rows="3" :aria-describedby="describedBy" :disabled="form.processing" placeholder="Product and schedule check results, or why the delivery fee changed"></textarea></template></Field>
            <p class="text-xs text-chocolate/65">Notes are visible to the team only. Saving a review updates the order total but does not create a payment.</p>
            <button class="admin-primary" :disabled="form.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ form.processing ? 'Saving…' : 'Save review' }}</button>
        </form>
        <p v-else class="text-sm">Review is locked because a payment link is open, payment was received, or the order is already being processed.</p>
    </section>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <section v-if="erzapSync" class="admin-card xl:col-span-2" aria-labelledby="erzap-title">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 id="erzap-title" class="m-0 text-xl">Erzap sync</h2><StatusBadge kind="sync" :status="erzapSync.status" /></div>
            <p class="admin-muted mb-0 mt-2 text-sm">{{ syncTypeLabels[erzapSync.type] ?? erzapSync.type }} · {{ erzapSync.status === 'synced' ? `sent ${witaTime(erzapSync.synced_at)}${erzapSync.external_ref ? ` · Ref ${erzapSync.external_ref}` : ''}` : erzapSync.last_error ?? 'Waiting for the next scheduled send.' }}</p>
            <Link v-if="can.integrations && erzapSync.status !== 'synced'" href="/admin/integrations" class="mt-3 inline-block text-sm font-bold underline">Open Erzap log</Link>
        </section>
        <section v-if="additions.length" class="admin-card xl:col-span-2" aria-labelledby="additions-title"><h2 id="additions-title" class="mb-5 text-xl">Customer additions</h2><ol class="space-y-4"><li v-for="addition in additions" :key="addition.id" class="border-b border-chocolate/10 pb-4 text-sm"><p class="admin-muted m-0 text-xs">{{ witaTime(addition.created_at) }} · via Order Code + WhatsApp</p><ul class="my-2 list-disc pl-5"><li v-for="(item, index) in addition.items" :key="index">{{ item.quantity }}× {{ item.name }}{{ item.variant ? ` (${item.variant})` : '' }} — {{ rupiah(item.subtotal) }}</li></ul><p class="m-0">Total {{ rupiah(addition.previous_total) }} → <strong>{{ rupiah(addition.new_total) }}</strong> (+{{ rupiah(addition.subtotal_added) }})</p></li></ol></section>
        <section class="admin-card"><h2 class="mb-5 text-xl">Status history</h2><ol class="space-y-4"><li v-for="change in history" :key="change.id" class="border-b border-chocolate/10 pb-4 text-sm"><p class="font-bold">{{ change.from_status ? `${orderStatusLabel(change.from_status)} → ` : '' }}{{ orderStatusLabel(change.to_status) }}</p><p class="text-xs text-chocolate/65">{{ change.created_at }} WITA · {{ change.actor_name ?? (change.from_status ? 'Unknown account' : 'Customer') }}</p><p v-if="change.note" class="whitespace-pre-wrap">{{ change.note }}</p></li></ol></section>
        <section class="admin-card"><h2 class="mb-5 text-xl">Review history</h2><p v-if="!reviews.data.length" class="text-sm text-chocolate/65">No staff reviews yet.</p><ol class="space-y-5"><li v-for="review in reviews.data" :key="review.id" class="border-b border-chocolate/10 pb-5 text-sm"><p class="font-bold">{{ review.actor_name ?? 'Unknown account' }}</p><p class="text-xs text-chocolate/65">{{ review.created_at }} WITA</p><p>Delivery fee: {{ feeLabel(review.previous_delivery_fee) }} → {{ feeLabel(review.delivery_fee) }}</p><p v-if="review.schedule">Schedule: {{ review.previous_schedule }} → {{ review.schedule }}</p><p class="whitespace-pre-wrap">{{ review.note }}</p></li></ol><Pagination :links="reviews.links" /></section>
    </div>
</template>

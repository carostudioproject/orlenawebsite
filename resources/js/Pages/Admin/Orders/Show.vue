<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import OrderSummary from '../../../Components/OrderSummary.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Field from '../../../Components/Admin/Field.vue';
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
const props = defineProps<{ order: Preorder; reviews: Paginated<Review>; history: StatusChange[]; payments: PaymentAttempt[]; canCancelPaid: boolean; additions: Addition[];
    dayLoad: { orders: number; capacity: number | null; closed: string | null };
    erzapSync: { id: number; type: string; status: string; attempts: number; last_error: string | null; synced_at: string | null; next_attempt_at: string | null; external_ref: string | null } | null;
}>();
const page = usePage<AdminProps>();
const can = computed(() => page.props.auth.can);
const errors = computed(() => page.props.errors as Record<string, string>);

const current = computed(() => props.payments[0] ?? null);
const openPayment = computed(() => props.payments.some(p => p.status === 'pending' || p.status === 'creating'));
const editable = computed(() => ['pending_review', 'confirmed'].includes(props.order.order_status)
    && ['not_created', 'failed', 'expired', 'cancelled'].includes(props.order.payment_status) && !openPayment.value);
const finished = computed(() => ['completed', 'cancelled'].includes(props.order.order_status));
const paidAfterCancel = computed(() => props.order.order_status === 'cancelled' && props.order.payment_status === 'paid');

const form = useForm({
    review_version: props.order.review_version, delivery_fee: props.order.delivery_fee ?? '' as number | string, note: '',
    requested_date: props.order.requested_date, requested_time: props.order.requested_time?.slice(0, 5) ?? '',
});
watch(() => props.order.review_version, () => {
    form.review_version = props.order.review_version; form.delivery_fee = props.order.delivery_fee ?? '';
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
async function confirmOrder() {
    const ok = await confirmDialog({
        title: 'Confirm this order?', icon: 'fa-circle-check', confirmLabel: 'Confirm & Create Payment',
        message: `A DOKU payment link for ${rupiah(props.order.total)} will be created for ${props.order.order_code}. Make sure the products, schedule and delivery fee are correct.`,
    });
    if (ok) act('/confirm', { review_version: props.order.review_version });
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
const whatsappLink = computed(() => {
    const payment = current.value;
    if (!payment?.payment_url || payment.status !== 'pending') return null;
    const text = `Halo ${props.order.customer.name}, pesanan ${props.order.order_code} sudah kami konfirmasi.\n\nTotal: ${rupiah(payment.amount)}\nLink pembayaran: ${payment.payment_url}\nBerlaku sampai: ${witaTime(payment.expires_at)}\n\nCek status pesanan kapan saja: ${window.location.origin}/cek-pesanan?code=${props.order.order_code}\n\nTerima kasih.`;
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
    <p v-if="!can.review" class="admin-alert admin-alert-info mb-6">Read-only mode. Your account can view orders and payments but cannot change them.</p>
    <p v-if="['pending_review', 'confirmed'].includes(order.order_status) && (dayLoad.closed || (dayLoad.capacity && dayLoad.orders > dayLoad.capacity))" class="admin-alert admin-alert-error mb-6"><i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i> {{ dayLoad.closed ?? `This PO date is over capacity: ${dayLoad.orders} orders for a capacity of ${dayLoad.capacity}.` }} Make sure production can handle it or agree on another date with the customer.</p>
    <p v-else-if="order.order_status === 'pending_review' && dayLoad.capacity" class="admin-muted mb-4 text-sm"><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> {{ dayLoad.orders }} of {{ dayLoad.capacity }} orders for this PO date.</p>
    <p v-if="additions.length && order.order_status === 'pending_review'" class="admin-alert admin-alert-info mb-6"><i class="fa-solid fa-cart-plus" aria-hidden="true"></i> The customer added items {{ additions.length }} time(s), last on {{ witaTime(additions[0].created_at) }}. Items and total below include the additions; check availability again before confirming.</p>
    <p v-if="paidAfterCancel" role="alert" class="admin-alert admin-alert-error mb-6">This order was cancelled but payment was received. Refund it manually in DOKU and record the result.</p>
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2"><section class="admin-card"><h2 class="mb-5 text-xl">Customer and fulfillment</h2><dl class="space-y-4 text-sm"><div><dt class="font-bold">Name</dt><dd>{{ order.customer.name }}</dd></div><div><dt class="font-bold">WhatsApp</dt><dd>{{ order.customer.whatsapp }}</dd></div><div v-if="order.customer.email"><dt class="font-bold">Email</dt><dd>{{ order.customer.email }}</dd></div><div><dt class="font-bold">Fulfillment</dt><dd>{{ order.fulfillment_method === 'delivery' ? `Delivery (Gojek/Grab) from ${order.outlet_name_snapshot}` : `Pickup at ${order.outlet_name_snapshot}` }}</dd></div><div><dt class="font-bold">Requested date / time</dt><dd>{{ order.requested_date }} {{ order.requested_time?.slice(0,5) }} WITA</dd></div><div v-if="order.delivery_address"><dt class="font-bold">Address</dt><dd class="whitespace-pre-wrap">{{ order.delivery_address }}</dd></div><div v-if="order.customer_note"><dt class="font-bold">Customer note</dt><dd class="whitespace-pre-wrap">{{ order.customer_note }}</dd></div><div v-if="order.card_message"><dt class="font-bold"><i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i> Greeting card</dt><dd class="whitespace-pre-wrap rounded-lg bg-cream p-3">{{ order.card_message }}</dd></div></dl></section><section class="admin-card"><h2 class="mb-5 text-xl">Order items</h2><OrderSummary :order="order" lang="en" /></section></div>

    <section class="admin-card mt-6">
        <h2 class="mb-4 text-xl">Payment</h2>
        <p v-if="errors.payment" role="alert" class="admin-alert admin-alert-error mb-4">{{ errors.payment }}</p>
        <div v-if="can.review && order.order_status === 'pending_review'" class="space-y-3 text-sm">
            <p>Check products, production capacity, schedule and delivery fee. Confirming creates a DOKU payment link for <strong>{{ rupiah(order.total) }}</strong>, valid for 24 hours (until {{ $page.props.poCutoff }} at the latest).</p>
            <p v-if="order.delivery_fee === null" class="admin-error-text">Set the delivery fee in the review form before confirming.</p>
            <button class="admin-primary" :disabled="busy || order.delivery_fee === null" @click="confirmOrder"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>{{ busy ? 'Processing…' : 'Confirm & Create Payment' }}</button>
        </div>
        <div v-if="current" class="space-y-3 text-sm" :class="{ 'mt-5 border-t border-chocolate/10 pt-5': order.order_status === 'pending_review' }">
            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3"><div><dt class="font-bold">Link #{{ current.attempt }} status</dt><dd>{{ paymentStatusLabel(current.status) }}</dd></div><div><dt class="font-bold">Amount</dt><dd>{{ rupiah(current.amount) }}</dd></div><div><dt class="font-bold">{{ current.status === 'paid' ? 'Paid at' : 'Valid until' }}</dt><dd>{{ witaTime(current.status === 'paid' ? current.paid_at : current.expires_at) }}</dd></div></dl>
            <div v-if="current.status === 'pending' && current.payment_url" class="flex flex-wrap gap-3">
                <button type="button" class="admin-primary" @click="copyLink(current.payment_url)"><i class="fa-solid fa-copy" aria-hidden="true"></i>{{ copied ? 'Link copied' : 'Copy payment link' }}</button>
                <a v-if="whatsappLink" :href="whatsappLink" target="_blank" rel="noopener noreferrer" class="admin-secondary"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i>Send via WhatsApp</a>
                <a :href="current.payment_url" target="_blank" rel="noopener noreferrer" class="admin-secondary"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>Open DOKU page</a>
            </div>
            <p v-if="current.status === 'creation_failed' && current.last_error" class="admin-error-text">{{ current.last_error }}</p>
            <button v-if="can.review && ['pending', 'expired', 'failed', 'cancelled'].includes(current.status)" type="button" class="admin-secondary" :disabled="busy" @click="act(`/payments/${current.id}/check`)"><i class="fa-solid fa-rotate" aria-hidden="true"></i>Check status in DOKU</button>
            <p v-if="current.status === 'pending'" class="text-xs text-chocolate/65">The status updates automatically when DOKU sends a notification. This page refreshes every 15 seconds.</p>
        </div>
        <div v-if="can.review && order.order_status === 'confirmed' && order.payment_status === 'not_created' && !openPayment" class="mt-4">
            <button type="button" class="admin-primary" :disabled="busy" @click="act('/payments/retry')"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i>Try creating the link again</button>
        </div>
        <form v-if="can.review && order.order_status === 'confirmed' && ['failed', 'expired', 'cancelled'].includes(order.payment_status) && !openPayment" class="mt-5 space-y-3" @submit.prevent="renew">
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
                <Field id="requested_date" label="PO date" :error="form.errors.requested_date"><template #default="{ describedBy }"><input id="requested_date" v-model="form.requested_date" type="date" required :aria-describedby="describedBy" :disabled="form.processing"></template></Field>
                <Field id="requested_time" label="Time (optional)" :error="form.errors.requested_time"><template #default="{ describedBy }"><input id="requested_time" v-model="form.requested_time" type="time" :aria-describedby="describedBy" :disabled="form.processing"></template></Field>
            </div>
            <p class="text-xs text-chocolate/65">Change the schedule only after agreeing with the customer. The new date follows the order deadline ({{ $page.props.poCutoff }}) and the closed dates in PO schedule.</p>
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

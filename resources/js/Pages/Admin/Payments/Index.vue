<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Pagination from '../../../Components/Admin/Pagination.vue';
import SearchInput from '../../../Components/Admin/SearchInput.vue';
import StatusBadge from '../../../Components/Admin/StatusBadge.vue';
import { useLiveFilters } from '../../../Composables/useLiveFilters';
import { rupiah } from '../../../Support/money';
import { paymentStatusLabel, witaTime } from '../../../Support/orderStatus';
import type { AdminProps, Paginated } from '../../../Types/admin';
defineOptions({ layout: AdminLayout });
interface PaymentRow {
    id: number; attempt: number; provider_order_id: string; amount: number; status: string; payment_type: string | null; transaction_id: string | null;
    expires_at: string | null; paid_at: string | null; created_at: string; reason: string | null; last_error: string | null;
    order: { id: number; order_code: string; order_status: string; customer: { name: string; whatsapp: string } } | null; creator: { name: string } | null;
}
const props = defineProps<{ payments: Paginated<PaymentRow>; filters: { search?: string; status?: string; from?: string; to?: string }; statuses: string[]; totals: { paid_today: number; pending: number; pending_amount: number; failed_week: number } }>();
const page = usePage<AdminProps>();
const { filters: live, loading, active, apply, reset } = useLiveFilters(() => '/admin/payments', { search: props.filters.search ?? '', status: props.filters.status ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
const checking = ref<number | null>(null);
function check(payment: PaymentRow) {
    if (!payment.order) return;
    router.post(`/admin/orders/${payment.order.id}/payments/${payment.id}/check`, {}, { preserveScroll: true, onStart: () => { checking.value = payment.id; }, onFinish: () => { checking.value = null; } });
}
const paymentError = computed(() => (page.props.errors as Record<string, string>).payment);
const method = (type: string | null) => (type ? type.replace(/_/g, ' ') : '—');
</script>
<template>
    <Head title="Payments · Orlena" />
    <h1 class="text-3xl font-bold">Payments</h1>
    <p class="admin-muted mt-2 text-sm">All Midtrans payment links. New links and cancellations are handled on the order page.</p>
    <ul class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <li class="admin-card"><p class="admin-muted m-0 text-sm">Received today</p><p class="m-0 mt-1 text-2xl font-bold tabular-nums">{{ rupiah(totals.paid_today) }}</p></li>
        <li class="admin-card"><p class="admin-muted m-0 text-sm">Awaiting payment</p><p class="m-0 mt-1 text-2xl font-bold tabular-nums">{{ totals.pending }} {{ totals.pending === 1 ? 'link' : 'links' }}</p><p class="admin-muted m-0 text-xs">{{ rupiah(totals.pending_amount) }}</p></li>
        <li class="admin-card"><p class="admin-muted m-0 text-sm">Failed (7 days)</p><p class="m-0 mt-1 text-2xl font-bold tabular-nums">{{ totals.failed_week }}</p></li>
    </ul>
    <form class="my-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
        <SearchInput id="search" v-model="live.search" label="Order Code, name or Midtrans ID" :loading="loading" />
        <div><label for="status" class="mb-2 block text-sm">Status</label><select id="status" v-model="live.status"><option value="">All</option><option v-for="status in statuses" :key="status" :value="status">{{ paymentStatusLabel(status) }}</option></select></div>
        <div><label for="from" class="mb-2 block text-sm">Created from</label><input id="from" v-model="live.from" type="date"></div>
        <div><label for="to" class="mb-2 block text-sm">To</label><input id="to" v-model="live.to" type="date" :min="live.from || undefined"></div>
        <button v-if="active" type="button" class="admin-secondary" @click="reset"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset</button>
    </form>
    <p v-if="paymentError" role="alert" class="admin-alert admin-alert-error mb-4">{{ paymentError }}</p>
    <div class="admin-card overflow-x-auto">
        <table v-if="payments.data.length" class="w-full text-left text-sm">
            <thead><tr><th>Order</th><th class="text-right">Amount</th><th>Status</th><th>Method</th><th>Time</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
                <tr v-for="payment in payments.data" :key="payment.id">
                    <td><Link v-if="payment.order" :href="`/admin/orders/${payment.order.id}`" class="font-bold underline">{{ payment.order.order_code }}</Link><span class="block text-xs">{{ payment.order?.customer.name }} · attempt {{ payment.attempt }}</span><span class="admin-muted block text-xs">{{ payment.provider_order_id }}</span></td>
                    <td class="whitespace-nowrap text-right font-bold tabular-nums">{{ rupiah(payment.amount) }}</td>
                    <td><StatusBadge kind="payment" :status="payment.status" /><span v-if="payment.last_error" class="admin-muted mt-1 block max-w-56 text-xs">{{ payment.last_error }}</span></td>
                    <td class="capitalize">{{ method(payment.payment_type) }}</td>
                    <td class="whitespace-nowrap text-xs"><span class="block">Created {{ witaTime(payment.created_at) }}</span><span v-if="payment.paid_at" class="block font-bold">Paid {{ witaTime(payment.paid_at) }}</span><span v-else-if="payment.expires_at && payment.status === 'pending'" class="admin-muted block">Valid until {{ witaTime(payment.expires_at) }}</span></td>
                    <td><div class="admin-actions">
                        <button v-if="page.props.auth.can.review && payment.order && ['pending', 'expired', 'cancelled', 'failed'].includes(payment.status)" type="button" class="admin-action" :disabled="checking === payment.id" :aria-label="`Check status of ${payment.provider_order_id}`" @click="check(payment)"><i class="fa-solid fa-rotate" :class="{ 'fa-spin': checking === payment.id }" aria-hidden="true"></i>Check status</button>
                        <Link v-if="payment.order" :href="`/admin/orders/${payment.order.id}`" class="admin-action" :aria-label="`Open order ${payment.order.order_code}`"><i class="fa-solid fa-eye" aria-hidden="true"></i>Order</Link>
                    </div></td>
                </tr>
            </tbody>
        </table>
        <p v-else class="m-0 py-8 text-center text-sm">{{ active ? 'No payments match these filters.' : 'No payments yet. Links are created when staff confirm an order.' }}</p>
    </div>
    <p class="mt-4 text-xs text-chocolate/60">{{ payments.total }} {{ payments.total === 1 ? 'payment' : 'payments' }}</p><Pagination :links="payments.links" />
</template>

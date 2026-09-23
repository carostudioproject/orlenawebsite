<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Pagination from '../../../Components/Admin/Pagination.vue';
import SearchInput from '../../../Components/Admin/SearchInput.vue';
import { useLiveFilters } from '../../../Composables/useLiveFilters';
import type { Paginated } from '../../../Types/admin';
import type { Preorder } from '../../../Types/ordering';
import { rupiah } from '../../../Support/money';
import { orderStatusLabels, paymentStatusLabels } from '../../../Support/orderStatus';
import StatusBadge from '../../../Components/Admin/StatusBadge.vue';
defineOptions({ layout: AdminLayout });
const props = defineProps<{ orders: Paginated<Preorder>; filters: { search?: string; date?: string; status?: string; payment?: string } }>();
const { filters: live, loading, active, apply, reset } = useLiveFilters(() => '/admin/orders', {
    search: props.filters.search ?? '', date: props.filters.date ?? '', status: props.filters.status ?? '', payment: props.filters.payment ?? '',
});
const paymentOptions = Object.entries(paymentStatusLabels).filter(([key]) => !['creating', 'creation_failed'].includes(key));
</script>
<template>
    <Head title="Orders · Orlena" /><h1 class="text-3xl font-bold">PO orders</h1><p class="admin-muted mt-2 text-sm">Check each request before confirming it with the customer.</p>
    <form class="my-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
        <SearchInput id="search" v-model="live.search" label="Order Code, name or WhatsApp" :loading="loading" />
        <div><label for="date" class="mb-2 block text-sm">PO date</label><input id="date" v-model="live.date" type="date"></div>
        <div><label for="status" class="mb-2 block text-sm">Order status</label><select id="status" v-model="live.status"><option value="">All</option><option v-for="(label, key) in orderStatusLabels" :key="key" :value="key">{{ label }}</option></select></div>
        <div><label for="payment" class="mb-2 block text-sm">Payment</label><select id="payment" v-model="live.payment"><option value="">All</option><option v-for="[key, label] in paymentOptions" :key="key" :value="key">{{ label }}</option></select></div>
        <button v-if="active" type="button" class="admin-secondary" @click="reset"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset</button>
    </form>
    <div class="admin-card overflow-x-auto"><table v-if="orders.data.length" class="w-full text-left text-sm"><thead><tr><th>Order Code</th><th>Customer</th><th>PO date</th><th>Total</th><th>Status</th><th>Payment</th><th class="text-right">Actions</th></tr></thead><tbody><tr v-for="order in orders.data" :key="order.id"><td><Link :href="`/admin/orders/${order.id}`" class="font-bold underline">{{ order.order_code }}</Link></td><td>{{ order.customer.name }}<span class="block text-xs">{{ order.customer.whatsapp }}</span></td><td class="whitespace-nowrap">{{ order.requested_date }}</td><td class="whitespace-nowrap">{{ rupiah(order.total) }}</td><td><StatusBadge kind="order" :status="order.order_status" /></td><td><StatusBadge kind="payment" :status="order.payment_status" /></td><td><div class="admin-actions"><Link :href="`/admin/orders/${order.id}`" class="admin-action" :aria-label="`View order ${order.order_code}`"><i class="fa-solid fa-eye" aria-hidden="true"></i>View</Link></div></td></tr></tbody></table><p v-else class="m-0 py-8 text-center text-sm">No matching orders.</p></div><Pagination :links="orders.links" />
</template>

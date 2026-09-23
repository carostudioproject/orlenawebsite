<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Pagination from '../../../Components/Admin/Pagination.vue';
import StatusBadge from '../../../Components/Admin/StatusBadge.vue';
import { rupiah } from '../../../Support/money';
import { witaTime } from '../../../Support/orderStatus';
import type { Paginated } from '../../../Types/admin';
defineOptions({ layout: AdminLayout });
interface OrderRow { id: number; order_code: string; customer: { name: string }; outlet_name_snapshot: string; fulfillment_method: string; requested_date: string; total: number; order_status: string; payment_status: string; created_at: string }
defineProps<{
    whatsapp: string; names: string[]; emails: string[];
    totals: { orders: number; spent: number; first_order_at: string; last_order_at: string };
    favourites: { name: string; variant: string | null; quantity: number }[]; orders: Paginated<OrderRow>;
}>();
</script>
<template>
    <Head :title="`${names[0]} · Customers · Orlena`" />
    <Link href="/admin/customers" class="admin-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to customers</Link>
    <div class="my-5 flex flex-wrap items-end justify-between gap-4">
        <div><h1 class="m-0 text-3xl font-bold">{{ names[0] }}</h1><p class="admin-muted m-0 mt-1 text-sm">{{ whatsapp }}<template v-if="emails.length"> · {{ emails.join(', ') }}</template></p><p v-if="names.length > 1" class="admin-muted m-0 mt-1 text-xs">Also ordered as: {{ names.slice(1).join(', ') }}</p></div>
        <a :href="`https://wa.me/${whatsapp}`" target="_blank" rel="noopener noreferrer" class="admin-primary"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i>Chat WhatsApp</a>
    </div>

    <ul class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <li class="admin-card"><p class="admin-muted m-0 text-sm">Orders</p><p class="m-0 mt-1 text-3xl font-bold tabular-nums">{{ totals.orders }}</p></li>
        <li class="admin-card"><p class="admin-muted m-0 text-sm">Total paid</p><p class="m-0 mt-1 text-2xl font-bold tabular-nums">{{ rupiah(totals.spent) }}</p></li>
        <li class="admin-card"><p class="admin-muted m-0 text-sm">First order</p><p class="m-0 mt-1 text-sm font-bold">{{ witaTime(totals.first_order_at) }}</p></li>
        <li class="admin-card"><p class="admin-muted m-0 text-sm">Last order</p><p class="m-0 mt-1 text-sm font-bold">{{ witaTime(totals.last_order_at) }}</p></li>
    </ul>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <section class="admin-card overflow-x-auto" aria-labelledby="orders-title">
            <h2 id="orders-title" class="mb-4 text-xl">Order history</h2>
            <table class="w-full text-left text-sm">
                <thead><tr><th>Order Code</th><th>PO date</th><th>Fulfillment</th><th class="text-right">Total</th><th>Status</th><th>Payment</th></tr></thead>
                <tbody><tr v-for="order in orders.data" :key="order.id"><td><Link :href="`/admin/orders/${order.id}`" class="font-bold underline">{{ order.order_code }}</Link><span class="admin-muted block text-xs">{{ order.customer.name }}</span></td><td class="whitespace-nowrap">{{ order.requested_date }}</td><td>{{ order.fulfillment_method === 'delivery' ? 'Delivery' : 'Pickup' }} · {{ order.outlet_name_snapshot }}</td><td class="whitespace-nowrap text-right tabular-nums">{{ rupiah(order.total) }}</td><td><StatusBadge kind="order" :status="order.order_status" /></td><td><StatusBadge kind="payment" :status="order.payment_status" /></td></tr></tbody>
            </table>
            <Pagination :links="orders.links" />
        </section>
        <section class="admin-card self-start" aria-labelledby="fav-title">
            <h2 id="fav-title" class="mb-4 text-xl">Favourite products</h2>
            <ol v-if="favourites.length" class="m-0 space-y-2 pl-5 text-sm"><li v-for="item in favourites" :key="`${item.name}-${item.variant}`"><span class="font-bold">{{ item.name }}</span><span v-if="item.variant" class="admin-muted"> ({{ item.variant }})</span> · {{ item.quantity }}×</li></ol>
            <p v-else class="admin-muted m-0 text-sm">No paid orders yet.</p>
        </section>
    </div>
</template>

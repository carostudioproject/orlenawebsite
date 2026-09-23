<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Pagination from '../../../Components/Admin/Pagination.vue';
import SearchInput from '../../../Components/Admin/SearchInput.vue';
import { useLiveFilters } from '../../../Composables/useLiveFilters';
import { rupiah } from '../../../Support/money';
import { witaTime } from '../../../Support/orderStatus';
import type { Paginated } from '../../../Types/admin';
defineOptions({ layout: AdminLayout });
interface CustomerRow { whatsapp: string; name: string; orders: number; spent: number; last_order_at: string }
const props = defineProps<{ customers: Paginated<CustomerRow>; filters: { search?: string; sort?: string } }>();
const { filters: live, loading, active, apply, reset } = useLiveFilters(() => '/admin/customers', { search: props.filters.search ?? '', sort: props.filters.sort ?? '' });
</script>
<template>
    <Head title="Customers · Orlena" />
    <h1 class="text-3xl font-bold">Customers</h1>
    <p class="admin-muted mt-2 text-sm">Grouped by WhatsApp number. Total spent only counts paid orders that were not cancelled.</p>
    <form class="my-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
        <SearchInput id="search" v-model="live.search" label="Name or WhatsApp" :loading="loading" />
        <div><label for="sort" class="mb-2 block text-sm">Sort by</label><select id="sort" v-model="live.sort"><option value="">Latest order</option><option value="spent">Highest total spent</option><option value="orders">Most orders</option></select></div>
        <button v-if="active" type="button" class="admin-secondary" @click="reset"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset</button>
    </form>
    <div class="admin-card overflow-x-auto">
        <table v-if="customers.data.length" class="w-full text-left text-sm">
            <thead><tr><th>Customer</th><th class="text-right">Orders</th><th class="text-right">Total spent</th><th>Last order</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
                <tr v-for="customer in customers.data" :key="customer.whatsapp">
                    <td><Link :href="`/admin/customers/${customer.whatsapp}`" class="font-bold underline">{{ customer.name }}</Link><span class="block text-xs">{{ customer.whatsapp }}</span></td>
                    <td class="text-right tabular-nums">{{ customer.orders }}</td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ rupiah(customer.spent) }}</td>
                    <td class="whitespace-nowrap text-xs">{{ witaTime(customer.last_order_at) }}</td>
                    <td><div class="admin-actions">
                        <a :href="`https://wa.me/${customer.whatsapp}`" target="_blank" rel="noopener noreferrer" class="admin-action-icon" :aria-label="`WhatsApp ${customer.name}`" title="WhatsApp"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i></a>
                        <Link :href="`/admin/customers/${customer.whatsapp}`" class="admin-action" :aria-label="`View ${customer.name}`"><i class="fa-solid fa-eye" aria-hidden="true"></i>View</Link>
                    </div></td>
                </tr>
            </tbody>
        </table>
        <p v-else class="m-0 py-8 text-center text-sm">{{ active ? 'No matching customers.' : 'No customers yet. They appear once orders come in.' }}</p>
    </div>
    <p class="mt-4 text-xs text-chocolate/60">{{ customers.total }} {{ customers.total === 1 ? 'customer' : 'customers' }}</p><Pagination :links="customers.links" />
</template>

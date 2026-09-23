<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DailyBarChart from '../../../Components/Admin/DailyBarChart.vue';
import { useLiveFilters } from '../../../Composables/useLiveFilters';
import { rupiah } from '../../../Support/money';
import { orderStatusLabels, paymentStatusLabel } from '../../../Support/orderStatus';
defineOptions({ layout: AdminLayout });
interface Report {
    filters: { from: string; to: string; outlet_id: number | null; product_id: number | null; status: string | null };
    labels: { outlet: string | null; product: string | null };
    summary: { revenue: number; transactions: number; average: number; product_sales: number; delivery_fees: number | null; items_sold: number; voided_orders: number; voided_amount: number };
    daily: { date: string; transactions: number; revenue: number }[];
    outlets: { outlet_id: number; outlet: string; transactions: number; revenue: number }[];
    products: { product_id: number | null; name: string; variant: string | null; category: string; quantity: number; revenue: number; orders: number }[];
    order_statuses: Record<string, number>; payment_statuses: Record<string, number>; placed_orders: number;
}
const props = defineProps<{ report: Report; productCount: number; outlets: { id: number; name: string }[]; products: { id: number; label: string }[] }>();
const f = props.report.filters;
const { filters: live, loading, apply } = useLiveFilters(() => '/admin/reports', {
    from: f.from, to: f.to, outlet_id: f.outlet_id ? String(f.outlet_id) : '', product_id: f.product_id ? String(f.product_id) : '', status: f.status ?? '',
}, []);

// Quick periods in WITA.
const wita = (date: Date) => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Makassar' }).format(date);
const today = wita(new Date());
const shift = (date: string, days: number) => { const d = new Date(`${date}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + days); return d.toISOString().slice(0, 10); };
const presets = computed(() => {
    const monthStart = `${today.slice(0, 8)}01`;
    const lastMonthEnd = shift(monthStart, -1);
    return [
        { label: 'Today', from: today, to: today }, { label: '7 days', from: shift(today, -6), to: today }, { label: '30 days', from: shift(today, -29), to: today },
        { label: 'This month', from: monthStart, to: today }, { label: 'Last month', from: `${lastMonthEnd.slice(0, 8)}01`, to: lastMonthEnd },
    ];
});
function preset(range: { from: string; to: string }) { live.from = range.from; live.to = range.to; }
const isPreset = (range: { from: string; to: string }) => live.from === range.from && live.to === range.to;
function reset() { Object.assign(live, { from: presets.value[3].from, to: presets.value[3].to, outlet_id: '', product_id: '', status: '' }); }

const query = computed(() => new URLSearchParams(Object.entries(live).filter(([, value]) => value !== '')).toString());
const s = computed(() => props.report.summary);
const compact = (value: number) => (value >= 1_000_000 ? `${(value / 1_000_000).toLocaleString('en-GB', { maximumFractionDigits: 1 })}M` : value >= 1000 ? `${Math.round(value / 1000)}K` : String(value));
const chart = computed(() => props.report.daily.map(day => ({ date: day.date, total: day.revenue })));
const maxOutlet = computed(() => Math.max(1, ...props.report.outlets.map(row => row.revenue)));
const maxProduct = computed(() => Math.max(1, ...props.report.products.map(row => row.quantity)));
const period = computed(() => {
    const format = (date: string) => new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
    return props.report.filters.from === props.report.filters.to ? format(props.report.filters.from) : `${format(props.report.filters.from)} – ${format(props.report.filters.to)}`;
});
</script>
<template>
    <Head title="Reports · Orlena" />
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="admin-eyebrow">Reports</p><h1 class="text-3xl font-bold">Sales &amp; performance</h1><p class="admin-muted mt-2 text-sm">{{ period }} · by payment time (WITA). Only paid orders are counted.</p></div>
        <div class="report-actions flex flex-wrap gap-2">
            <a :href="`/admin/reports/print?${query}`" target="_blank" rel="noopener" class="admin-secondary"><i class="fa-solid fa-print" aria-hidden="true"></i>Print</a>
            <a :href="`/admin/reports/pdf?${query}`" class="admin-secondary"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i>PDF</a>
            <a :href="`/admin/reports/excel?${query}`" class="admin-primary"><i class="fa-solid fa-file-excel" aria-hidden="true"></i>Excel</a>
        </div>
    </div>

    <form class="report-actions admin-card my-6 space-y-4" @submit.prevent="apply">
        <div class="admin-segment" role="group" aria-label="Quick period"><button v-for="range in presets" :key="range.label" type="button" :aria-pressed="isPreset(range)" @click="preset(range)">{{ range.label }}</button></div>
        <div class="grid grid-cols-2 items-end gap-3 md:grid-cols-3 xl:grid-cols-[auto_auto_minmax(0,1fr)_minmax(0,1.3fr)_minmax(0,1fr)_auto]">
            <div><label for="from" class="mb-2 block text-sm">From</label><input id="from" v-model="live.from" type="date" required :max="live.to"></div>
            <div><label for="to" class="mb-2 block text-sm">To</label><input id="to" v-model="live.to" type="date" required :min="live.from"></div>
            <div><label for="outlet_id" class="mb-2 block text-sm">Outlet</label><select id="outlet_id" v-model="live.outlet_id" class="w-full"><option value="">All outlets</option><option v-for="outlet in outlets" :key="outlet.id" :value="String(outlet.id)">{{ outlet.name }}</option></select></div>
            <div><label for="product_id" class="mb-2 block text-sm">Product</label><select id="product_id" v-model="live.product_id" class="w-full"><option value="">All products</option><option v-for="product in products" :key="product.id" :value="String(product.id)">{{ product.label }}</option></select></div>
            <div><label for="status" class="mb-2 block text-sm">Order status</label><select id="status" v-model="live.status" class="w-full"><option value="">All</option><option v-for="(label, key) in orderStatusLabels" :key="key" :value="key">{{ label }}</option></select></div>
            <div class="flex items-center gap-3"><button type="button" class="admin-secondary" @click="reset"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset</button><span v-if="loading" class="admin-muted text-sm" role="status"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span class="sr-only">Loading…</span></span></div>
        </div>
    </form>

    <ul class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <li class="admin-card"><p class="admin-muted m-0 text-sm">{{ report.filters.product_id ? 'Product sales' : 'Paid revenue' }}</p><p class="m-0 mt-1 text-2xl font-bold tabular-nums sm:text-3xl">{{ rupiah(s.revenue) }}</p><p v-if="s.delivery_fees !== null" class="admin-muted m-0 mt-1 text-xs">Products {{ rupiah(s.product_sales) }} + delivery {{ rupiah(s.delivery_fees) }}</p></li>
        <li class="admin-card"><p class="admin-muted m-0 text-sm">Paid transactions</p><p class="m-0 mt-1 text-2xl font-bold tabular-nums sm:text-3xl">{{ s.transactions }}</p></li>
        <li class="admin-card"><p class="admin-muted m-0 text-sm">Average per transaction</p><p class="m-0 mt-1 text-2xl font-bold tabular-nums sm:text-3xl">{{ rupiah(s.average) }}</p></li>
        <li class="admin-card"><p class="admin-muted m-0 text-sm">Items sold</p><p class="m-0 mt-1 text-2xl font-bold tabular-nums sm:text-3xl">{{ s.items_sold }}</p></li>
    </ul>
    <p v-if="s.voided_orders" class="admin-alert admin-alert-info mt-4"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> {{ s.voided_orders }} order(s) worth {{ rupiah(s.voided_amount) }} were cancelled or refunded after payment and are not counted as revenue.</p>

    <section class="admin-card mt-6" aria-labelledby="daily-title">
        <h2 id="daily-title" class="mb-5 text-xl">{{ report.filters.product_id ? 'Daily product sales' : 'Daily revenue' }}</h2>
        <DailyBarChart :data="chart" caption="Revenue per day" unit="Revenue" :format="rupiah" :tick="compact" />
    </section>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <section class="admin-card overflow-x-auto" aria-labelledby="outlet-title">
            <h2 id="outlet-title" class="mb-4 text-xl">By outlet</h2>
            <table v-if="report.outlets.length" class="w-full text-left text-sm">
                <thead><tr><th>Outlet</th><th class="text-right">Transactions</th><th class="text-right">Revenue</th></tr></thead>
                <tbody><tr v-for="row in report.outlets" :key="row.outlet_id"><td class="report-bar"><span aria-hidden="true" :style="{ width: `${(row.revenue / maxOutlet) * 100}%` }"></span><span class="font-bold">{{ row.outlet }}</span></td><td class="text-right tabular-nums">{{ row.transactions }}</td><td class="whitespace-nowrap text-right tabular-nums">{{ rupiah(row.revenue) }}</td></tr></tbody>
            </table>
            <p v-else class="admin-muted m-0 text-sm">No paid transactions in this period.</p>
        </section>

        <section class="admin-card overflow-x-auto" aria-labelledby="status-title">
            <h2 id="status-title" class="mb-1 text-xl">Order status</h2>
            <p class="admin-muted mb-4 text-sm">{{ report.placed_orders }} order(s) placed in this period (by order date).</p>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <dl class="m-0 space-y-1.5 text-sm"><dt class="admin-eyebrow mb-2">Orders</dt><div v-for="(label, key) in orderStatusLabels" :key="key" class="flex justify-between gap-3"><dd class="m-0">{{ label }}</dd><dd class="m-0 font-bold tabular-nums">{{ report.order_statuses[key] ?? 0 }}</dd></div></dl>
                <dl class="m-0 space-y-1.5 text-sm"><dt class="admin-eyebrow mb-2">Payments</dt><div v-for="(total, key) in report.payment_statuses" :key="key" class="flex justify-between gap-3"><dd class="m-0">{{ paymentStatusLabel(String(key)) }}</dd><dd class="m-0 font-bold tabular-nums">{{ total }}</dd></div><p v-if="!Object.keys(report.payment_statuses).length" class="admin-muted m-0">No orders.</p></dl>
            </div>
        </section>
    </div>

    <section class="admin-card mt-6 overflow-x-auto" aria-labelledby="products-title">
        <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2"><h2 id="products-title" class="m-0 text-xl">Best-selling products</h2><p v-if="productCount > report.products.length" class="admin-muted m-0 text-sm">Top 20 of {{ productCount }} products. The Excel file lists all of them.</p></div>
        <table v-if="report.products.length" class="w-full text-left text-sm">
            <thead><tr><th class="w-10">#</th><th>Product</th><th>Category</th><th class="text-right">Sold</th><th class="text-right">Orders</th><th class="text-right">Sales</th></tr></thead>
            <tbody><tr v-for="(row, index) in report.products" :key="`${row.product_id}-${row.name}-${row.variant}`"><td class="tabular-nums">{{ index + 1 }}</td><td class="report-bar min-w-48"><span aria-hidden="true" :style="{ width: `${(row.quantity / maxProduct) * 100}%` }"></span><span class="font-bold">{{ row.name }}</span><span v-if="row.variant" class="admin-muted"> ({{ row.variant }})</span></td><td>{{ row.category }}</td><td class="text-right font-bold tabular-nums">{{ row.quantity }}</td><td class="text-right tabular-nums">{{ row.orders }}</td><td class="whitespace-nowrap text-right tabular-nums">{{ rupiah(row.revenue) }}</td></tr></tbody>
        </table>
        <p v-else class="admin-muted m-0 text-sm">No products sold in this period.</p>
    </section>
</template>

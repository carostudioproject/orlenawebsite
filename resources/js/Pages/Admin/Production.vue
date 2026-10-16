<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
defineOptions({ layout: AdminLayout });
interface Row { name: string; variant: string | null; category: string; quantity: number; by_outlet: Record<string, number> }
interface RecapOrder { code: string; time: string | null; method: 'pickup' | 'delivery'; customer: string | null; note: string | null; card: string | null; items: { label: string; contents: string[] }[] }
interface Recap {
    date: string; final: boolean; cutoff: string; outlets: string[];
    summary: { orders: number; pickup: number; delivery: number; pieces: number };
    products: Row[]; packages: Row[]; unpaid: number; orders: { outlet: string; orders: RecapOrder[] }[];
}
const props = defineProps<{ recap: Recap; whatsappUrl: string; whatsappText: string }>();

// Rekap CK (central kitchen): paid orders for one date. Tomorrow by default, the date the kitchen prepares tonight.
const date = ref(props.recap.date);
const loading = ref(false);
function show(value: string) {
    if (!value) return;
    date.value = value;
    router.get('/admin/production', { date: value }, { preserveState: true, preserveScroll: true, onStart: () => { loading.value = true; }, onFinish: () => { loading.value = false; } });
}
const shift = (days: number) => {
    const day = new Date(`${new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Makassar' })}T00:00:00Z`);
    day.setUTCDate(day.getUTCDate() + days);
    return day.toISOString().slice(0, 10);
};
const quick = [{ label: 'Hari ini', value: shift(0) }, { label: 'Besok', value: shift(1) }, { label: 'Lusa', value: shift(2) }];
const dayLabel = computed(() => new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${props.recap.date}T00:00:00Z`)));
const cutoffLabel = computed(() => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Makassar' }).format(new Date(props.recap.cutoff)) + ' WITA');
const label = (row: Row) => `${row.name}${row.variant ? ` (${row.variant})` : ''}`;

const copied = ref(false);
async function copy() {
    try { await navigator.clipboard.writeText(props.whatsappText); copied.value = true; window.setTimeout(() => { copied.value = false; }, 1600); } catch { copied.value = false; }
}
</script>
<template>
    <Head title="Rekap CK · Orlena" />
    <p class="admin-eyebrow">Kitchen</p>
    <h1 class="text-3xl font-bold">Rekap CK</h1>
    <p class="admin-muted mt-2 max-w-3xl text-sm">Paid orders the central kitchen produces for one pickup/delivery date. It becomes final at the date's cut-off (D-1, {{ cutoffLabel }}). Send it as a PDF or WhatsApp message.</p>

    <div class="mt-6 flex flex-wrap items-end gap-3">
        <div><label for="recap-date" class="mb-2 block text-sm">Date</label><input id="recap-date" v-model="date" type="date" @change="show(date)"></div>
        <div class="flex gap-2" role="group" aria-label="Quick dates"><button v-for="option in quick" :key="option.value" type="button" :class="recap.date === option.value ? 'admin-primary' : 'admin-secondary'" @click="show(option.value)">{{ option.label }}</button></div>
        <span v-if="loading" class="admin-muted text-sm" role="status"><i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i> Loading…</span>
    </div>

    <section class="admin-card mt-6" aria-labelledby="recap-title">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="recap-title" class="m-0 text-xl">{{ dayLabel }}</h2>
                <p class="m-0 mt-1 text-sm"><span class="rounded-full px-2.5 py-0.5 text-xs font-bold text-white" :class="recap.final ? 'bg-matcha' : 'bg-berry'">{{ recap.final ? 'FINAL' : 'SEMENTARA' }}</span>
                    <span class="admin-muted"> {{ recap.final ? 'Cut-off has passed; this list will not change.' : 'Before cut-off: more paid orders can still come in.' }}</span></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a :href="`/admin/production/pdf?date=${recap.date}`" class="admin-secondary"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i>Download PDF</a>
                <a :href="whatsappUrl" target="_blank" rel="noopener noreferrer" class="admin-primary"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i>Send via WhatsApp</a>
                <button type="button" class="admin-secondary" @click="copy"><i class="fa-solid fa-copy" aria-hidden="true"></i>{{ copied ? 'Copied' : 'Copy text' }}</button>
            </div>
        </div>
        <ul class="m-0 mt-5 grid list-none grid-cols-2 gap-3 p-0 sm:grid-cols-4">
            <li class="rounded-xl bg-cream p-3"><p class="admin-muted m-0 text-xs">Paid orders</p><p class="m-0 text-2xl font-bold tabular-nums">{{ recap.summary.orders }}</p></li>
            <li class="rounded-xl bg-cream p-3"><p class="admin-muted m-0 text-xs">Pickup</p><p class="m-0 text-2xl font-bold tabular-nums">{{ recap.summary.pickup }}</p></li>
            <li class="rounded-xl bg-cream p-3"><p class="admin-muted m-0 text-xs">Delivery</p><p class="m-0 text-2xl font-bold tabular-nums">{{ recap.summary.delivery }}</p></li>
            <li class="rounded-xl bg-cream p-3"><p class="admin-muted m-0 text-xs">Items to produce</p><p class="m-0 text-2xl font-bold tabular-nums">{{ recap.summary.pieces }}</p></li>
        </ul>
        <p v-if="recap.unpaid" class="admin-alert admin-alert-info mt-4 text-sm">{{ recap.unpaid }} confirmed order(s) for this date are not paid yet and are not in this recap.</p>
    </section>

    <section class="admin-card mt-6" aria-labelledby="products-title">
        <h2 id="products-title" class="mb-4 text-xl">To produce</h2>
        <p v-if="!recap.products.length" class="admin-muted m-0 text-sm">No paid orders for this date yet.</p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr><th class="text-left">Product</th><th class="text-left">Category</th><th v-for="outlet in recap.outlets" :key="outlet" class="text-right">{{ outlet }}</th><th class="text-right">Total</th></tr></thead>
                <tbody>
                    <tr v-for="row in recap.products" :key="label(row)"><td class="font-bold">{{ label(row) }}</td><td class="admin-muted">{{ row.category }}</td><td v-for="outlet in recap.outlets" :key="outlet" class="text-right tabular-nums">{{ row.by_outlet[outlet] || '' }}</td><td class="text-right font-bold tabular-nums">{{ row.quantity }}</td></tr>
                </tbody>
            </table>
        </div>
        <template v-if="recap.packages.length">
            <h3 class="mb-2 mt-6 text-base">Hampers and box packaging</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr><th class="text-left">Package</th><th v-for="outlet in recap.outlets" :key="outlet" class="text-right">{{ outlet }}</th><th class="text-right">Total</th></tr></thead>
                    <tbody><tr v-for="row in recap.packages" :key="label(row)"><td class="font-bold">{{ label(row) }}</td><td v-for="outlet in recap.outlets" :key="outlet" class="text-right tabular-nums">{{ row.by_outlet[outlet] || '' }}</td><td class="text-right font-bold tabular-nums">{{ row.quantity }}</td></tr></tbody>
                </table>
            </div>
        </template>
    </section>

    <section v-for="group in recap.orders" :key="group.outlet" class="admin-card mt-6" :aria-labelledby="`outlet-${group.outlet}`">
        <h2 :id="`outlet-${group.outlet}`" class="mb-4 text-xl">{{ group.outlet }} <span class="admin-muted text-sm font-normal">· {{ group.orders.length }} order(s)</span></h2>
        <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0 lg:grid-cols-2">
            <li v-for="order in group.orders" :key="order.code" class="rounded-xl border border-chocolate/10 p-3 text-sm">
                <p class="m-0 font-bold">{{ order.time ?? '—' }} · {{ order.method === 'delivery' ? 'Delivery' : 'Pickup' }} <span class="admin-muted font-normal">· {{ order.code }} · {{ order.customer }}</span></p>
                <ul class="m-0 mt-1 list-none p-0">
                    <li v-for="item in order.items" :key="item.label">{{ item.label }}<span v-for="content in item.contents" :key="content" class="admin-muted block pl-4 text-xs">isi: {{ content }}</span></li>
                </ul>
                <p v-if="order.card" class="m-0 mt-2 rounded-lg bg-cream p-2 text-xs"><i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i> {{ order.card }}</p>
                <p v-if="order.note" class="admin-muted m-0 mt-1 text-xs">Note: {{ order.note }}</p>
            </li>
        </ul>
    </section>
</template>

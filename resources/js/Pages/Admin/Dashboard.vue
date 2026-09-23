<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import DailyBarChart from '../../Components/Admin/DailyBarChart.vue';
import StatusBadge from '../../Components/Admin/StatusBadge.vue';
import { rupiah } from '../../Support/money';
import { witaTime } from '../../Support/orderStatus';
import type { AdminProps } from '../../Types/admin';
defineOptions({ layout: AdminLayout });
interface ActionOrder { id: number; order_code: string; requested_date: string; total: number; order_status: string; payment_status: string; customer: { name: string } | null }
const props = defineProps<{
    today: string;
    orders: {
        pending_review: number; awaiting_payment: number; to_process: number; tomorrow: number; paid_this_month: number; paid_last_month: number;
        daily: { date: string; total: number }[]; payment_breakdown: Record<string, number>; needs_action: ActionOrder[];
    } | null;
    catalog: { products: number; active_products: number; outlets: number; active_outlets: number; categories: number } | null;
    content: { published_posts: number; draft_posts: number; customized_sections: number; recent_posts: { id: number; title: string; is_published: boolean; updated_at: string }[] } | null;
}>();
const page = usePage<AdminProps>();

const hour = Number(new Intl.DateTimeFormat('en-GB', { hour: 'numeric', hourCycle: 'h23', timeZone: 'Asia/Makassar' }).format(new Date()));
const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
const todayLabel = new Intl.DateTimeFormat('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(props.today + 'T00:00:00Z'));

const kpis = computed(() => props.orders ? [
    { label: 'Awaiting review', value: String(props.orders.pending_review), note: 'New orders to check', icon: 'fa-inbox', tint: '#f7dbb3', href: '/admin/orders?status=pending_review' },
    { label: 'Awaiting payment', value: String(props.orders.awaiting_payment), note: 'Payment link sent', icon: 'fa-hourglass-half', tint: '#75a4cc33', href: '/admin/orders?payment=pending' },
    { label: 'Paid, to prepare', value: String(props.orders.to_process), note: `${props.orders.tomorrow} PO for tomorrow`, icon: 'fa-fire-burner', tint: '#4d714126', href: '/admin/orders?payment=paid' },
    { label: 'Paid this month', value: rupiah(props.orders.paid_this_month), note: `Last month ${rupiah(props.orders.paid_last_month)}`, icon: 'fa-wallet', tint: '#f3a8ac59', href: null },
] : []);
const ordersIn14Days = computed(() => props.orders?.daily.reduce((sum, point) => sum + point.total, 0) ?? 0);
const breakdown = computed(() => {
    const entries = Object.entries(props.orders?.payment_breakdown ?? {}).sort((a, b) => b[1] - a[1]);
    const max = Math.max(1, ...entries.map(([, total]) => total));
    return entries.map(([status, total]) => ({ status, total, width: (total / max) * 100 }));
});
const percent = (part: number, whole: number) => (whole ? Math.round((part / whole) * 100) : 0);
</script>
<template>
    <Head title="Overview · Orlena" />
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div><p class="admin-eyebrow">{{ todayLabel }}</p><h1 class="text-3xl font-bold">{{ greeting }}, {{ page.props.auth.user.name.split(' ')[0] }}</h1><p class="admin-muted mt-1 text-sm">Your work at a glance for the {{ page.props.auth.user.role_label }} role.</p></div>
        <div class="flex flex-wrap gap-2">
            <Link v-if="orders" href="/admin/orders" class="admin-primary"><i class="fa-solid fa-receipt" aria-hidden="true"></i>Manage orders</Link>
            <Link v-if="content" href="/admin/posts/create" :class="orders ? 'admin-secondary' : 'admin-primary'"><i class="fa-solid fa-pen" aria-hidden="true"></i>Write an article</Link>
        </div>
    </div>

    <template v-if="orders">
        <section aria-label="Order indicators" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <component :is="kpi.href ? Link : 'div'" v-for="kpi in kpis" :key="kpi.label" :href="kpi.href ?? undefined" class="admin-card admin-kpi">
                <span class="admin-kpi-icon" :style="{ background: kpi.tint }"><i class="fa-solid" :class="kpi.icon" aria-hidden="true"></i></span>
                <span class="min-w-0"><span class="admin-muted block text-sm">{{ kpi.label }}</span><span class="admin-kpi-value block truncate">{{ kpi.value }}</span><span class="admin-muted block text-xs">{{ kpi.note }}</span></span>
            </component>
        </section>

        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <section class="admin-card" aria-labelledby="chart-title">
                <div class="mb-6 flex flex-wrap items-baseline justify-between gap-2"><h2 id="chart-title" class="text-lg">New orders, last 14 days</h2><p class="admin-muted m-0 text-sm"><strong class="text-chocolate">{{ ordersIn14Days }}</strong> orders</p></div>
                <DailyBarChart :data="orders.daily" caption="New orders per day, last 14 days" unit="orders" />
            </section>
            <section class="admin-card" aria-labelledby="payment-title">
                <h2 id="payment-title" class="mb-5 text-lg">Payment status</h2>
                <p v-if="!breakdown.length" class="admin-muted text-sm">No active orders yet.</p>
                <ul class="space-y-4">
                    <li v-for="row in breakdown" :key="row.status"><div class="mb-1.5 flex items-center justify-between gap-3"><StatusBadge kind="payment" :status="row.status" /><span class="text-sm font-bold tabular-nums">{{ row.total }}</span></div><div class="h-1.5 rounded-full bg-cream" aria-hidden="true"><div class="h-full rounded-full bg-chocolate/80" :style="{ width: `${row.width}%` }"></div></div></li>
                </ul>
                <p class="admin-muted mt-5 text-xs">Cancelled orders are not included.</p>
            </section>
        </div>

        <section class="admin-card mt-6" aria-labelledby="action-title">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3"><div><h2 id="action-title" class="text-lg">Needs action</h2><p class="admin-muted m-0 text-sm">New orders to review and paid orders to prepare, nearest PO date first.</p></div><Link href="/admin/orders" class="admin-secondary">All orders<i class="fa-solid fa-arrow-right text-xs" aria-hidden="true"></i></Link></div>
            <div class="overflow-x-auto">
                <table v-if="orders.needs_action.length" class="w-full text-left text-sm"><thead><tr><th>Order</th><th>Customer</th><th>PO date</th><th class="text-right">Total</th><th>Status</th><th class="text-right">Actions</th></tr></thead><tbody>
                    <tr v-for="order in orders.needs_action" :key="order.id"><td><Link :href="`/admin/orders/${order.id}`" class="font-bold underline">{{ order.order_code }}</Link></td><td>{{ order.customer?.name ?? '—' }}</td><td class="whitespace-nowrap">{{ order.requested_date }}</td><td class="whitespace-nowrap text-right tabular-nums">{{ rupiah(order.total) }}</td><td><div class="flex flex-wrap gap-1.5"><StatusBadge kind="order" :status="order.order_status" /><StatusBadge kind="payment" :status="order.payment_status" /></div></td><td><div class="admin-actions"><Link :href="`/admin/orders/${order.id}`" class="admin-action" :aria-label="`Open order ${order.order_code}`"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i>{{ order.order_status === 'pending_review' ? 'Review' : 'Process' }}</Link></div></td></tr>
                </tbody></table>
                <div v-else class="flex flex-col items-center gap-2 py-10 text-center"><span class="admin-kpi-icon" style="background: #4d714126"><i class="fa-solid fa-check" aria-hidden="true"></i></span><p class="m-0 font-bold">All clear</p><p class="admin-muted m-0 text-sm">No orders are waiting for action.</p></div>
            </div>
        </section>
    </template>

    <div v-if="catalog || content" class="mt-6 grid grid-cols-1 gap-6" :class="catalog && content ? 'xl:grid-cols-2' : ''">
        <section v-if="catalog" class="admin-card" aria-labelledby="catalog-title">
            <div class="mb-5 flex items-center justify-between gap-3"><h2 id="catalog-title" class="text-lg">PO catalog</h2><Link href="/admin/products" class="admin-secondary"><i class="fa-solid fa-cookie-bite" aria-hidden="true"></i>Open catalog</Link></div>
            <dl class="space-y-5">
                <div><div class="mb-1.5 flex justify-between text-sm"><dt>Active products</dt><dd class="m-0 font-bold tabular-nums">{{ catalog.active_products }} / {{ catalog.products }}</dd></div><div class="h-1.5 rounded-full bg-cream" aria-hidden="true"><div class="h-full rounded-full bg-matcha" :style="{ width: `${percent(catalog.active_products, catalog.products)}%` }"></div></div></div>
                <div><div class="mb-1.5 flex justify-between text-sm"><dt>Outlets taking PO</dt><dd class="m-0 font-bold tabular-nums">{{ catalog.active_outlets }} / {{ catalog.outlets }}</dd></div><div class="h-1.5 rounded-full bg-cream" aria-hidden="true"><div class="h-full rounded-full bg-matcha" :style="{ width: `${percent(catalog.active_outlets, catalog.outlets)}%` }"></div></div></div>
                <div class="flex justify-between text-sm"><dt>Categories</dt><dd class="m-0 font-bold tabular-nums">{{ catalog.categories }}</dd></div>
            </dl>
        </section>
        <section v-if="content" class="admin-card" aria-labelledby="content-title">
            <div class="mb-5 flex items-center justify-between gap-3"><h2 id="content-title" class="text-lg">Website</h2><Link href="/admin/content" class="admin-secondary"><i class="fa-solid fa-pen-ruler" aria-hidden="true"></i>Homepage content</Link></div>
            <div class="mb-5 grid grid-cols-3 gap-3 text-center">
                <div class="rounded-xl bg-cream p-3"><p class="admin-kpi-value is-small m-0">{{ content.published_posts }}</p><p class="admin-muted m-0 text-xs">Published</p></div>
                <div class="rounded-xl bg-cream p-3"><p class="admin-kpi-value is-small m-0">{{ content.draft_posts }}</p><p class="admin-muted m-0 text-xs">Drafts</p></div>
                <div class="rounded-xl bg-cream p-3"><p class="admin-kpi-value is-small m-0">{{ content.customized_sections }}</p><p class="admin-muted m-0 text-xs">Edited sections</p></div>
            </div>
            <h3 class="mb-2 text-sm font-bold">Recently edited articles</h3>
            <ul class="divide-y divide-chocolate/10">
                <li v-for="post in content.recent_posts" :key="post.id" class="flex items-center justify-between gap-3 py-2.5 text-sm"><span class="min-w-0"><span class="block truncate font-bold">{{ post.title }}</span><span class="admin-muted text-xs">{{ post.is_published ? 'Published' : 'Draft' }} · {{ witaTime(post.updated_at) }}</span></span><Link :href="`/admin/posts/${post.id}/edit`" class="admin-action shrink-0" :aria-label="`Edit ${post.title}`"><i class="fa-solid fa-pen" aria-hidden="true"></i>Edit</Link></li>
            </ul>
        </section>
    </div>
</template>

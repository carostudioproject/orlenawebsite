<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Pagination from '../../../Components/Admin/Pagination.vue';
import SearchInput from '../../../Components/Admin/SearchInput.vue';
import StatusBadge from '../../../Components/Admin/StatusBadge.vue';
import { useLiveFilters } from '../../../Composables/useLiveFilters';
import { syncTypeLabels, witaTime } from '../../../Support/orderStatus';
import type { AdminProps, Paginated } from '../../../Types/admin';
defineOptions({ layout: AdminLayout });
interface SyncRow { id: number; type: string; status: string; attempts: number; last_error: string | null; external_ref: string | null; subject_id: number; order_code: string | null; created_at: string; synced_at: string | null; next_attempt_at: string | null }
const props = defineProps<{
    configured: boolean; missingSettings: string[]; defaultOutlet: boolean; syncs: Paginated<SyncRow>; filters: { status?: string; search?: string }; labels: Record<string, string>;
    counts: Record<string, number>; mapping: { outlets: { mapped: number; total: number }; products: { mapped: number; total: number } };
}>();
const page = usePage<AdminProps>();
const { filters: live, loading, active, apply, reset } = useLiveFilters(() => '/admin/integrations', { search: props.filters.search ?? '', status: props.filters.status ?? '' });
const retrying = ref<number | null>(null);
function retry(sync: SyncRow) {
    router.post(`/admin/integrations/syncs/${sync.id}/retry`, {}, { preserveScroll: true, onStart: () => { retrying.value = sync.id; }, onFinish: () => { retrying.value = null; } });
}
const syncError = computed(() => (page.props.errors as Record<string, string>).sync);
const tiles = computed(() => [
    { label: 'Sent', value: props.counts.synced ?? 0, icon: 'fa-circle-check' },
    { label: 'Waiting', value: (props.counts.pending ?? 0) + (props.counts.waiting_config ?? 0), icon: 'fa-hourglass-half' },
    { label: 'Needs mapping', value: props.counts.needs_mapping ?? 0, icon: 'fa-link-slash' },
    { label: 'Failed', value: props.counts.failed ?? 0, icon: 'fa-triangle-exclamation' },
]);
</script>
<template>
    <Head title="Erzap integration · Orlena" />
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="admin-eyebrow">Integration</p><h1 class="text-3xl font-bold">Erzap</h1><p class="admin-muted mt-2 text-sm">Every paid order is sent to Erzap as a sales order (OLZAP API). Cancellations after payment are corrected in Erzap by hand.</p></div>
        <Link href="/admin/integrations/mapping" class="admin-primary"><i class="fa-solid fa-link" aria-hidden="true"></i>Outlet &amp; product mapping</Link>
    </div>

    <div role="status" class="admin-alert mt-6" :class="configured ? 'admin-alert-success' : 'admin-alert-info'">
        <p class="m-0 font-bold"><i class="fa-solid" :class="configured ? 'fa-plug-circle-check' : 'fa-plug-circle-xmark'" aria-hidden="true"></i> {{ configured ? 'Connected to Erzap' : 'Erzap is not configured yet' }}</p>
        <p class="m-0 mt-1 text-sm">{{ configured ? 'The queue is sent automatically every 5 minutes. Failures are retried automatically up to 5 times.' : `Paid orders are still queued and will be sent automatically once a developer sets ${missingSettings.join(', ')} on the server.` }}</p>
    </div>

    <ul class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <li v-for="tile in tiles" :key="tile.label" class="admin-card"><p class="admin-muted m-0 text-sm"><i class="fa-solid" :class="tile.icon" aria-hidden="true"></i> {{ tile.label }}</p><p class="m-0 mt-1 text-3xl font-bold tabular-nums">{{ tile.value }}</p></li>
    </ul>
    <p class="admin-muted mt-3 text-sm">Mapping: {{ mapping.outlets.mapped }}/{{ mapping.outlets.total }} outlets have an Erzap outlet ID{{ defaultOutlet ? ' (others use the default outlet)' : '' }}, and {{ mapping.products.mapped }}/{{ mapping.products.total }} products have a barcode.</p>

    <h2 class="mt-8 text-xl">Sync log</h2>
    <form class="my-4 flex flex-wrap items-end gap-3" @submit.prevent="apply">
        <SearchInput id="search" v-model="live.search" label="Order Code" :loading="loading" />
        <div><label for="status" class="mb-2 block text-sm">Status</label><select id="status" v-model="live.status"><option value="">All</option><option v-for="(label, key) in labels" :key="key" :value="key">{{ label }}</option></select></div>
        <button v-if="active" type="button" class="admin-secondary" @click="reset"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset</button>
    </form>
    <p v-if="syncError" role="alert" class="admin-alert admin-alert-error mb-4">{{ syncError }}</p>
    <div class="admin-card overflow-x-auto">
        <table v-if="syncs.data.length" class="w-full text-left text-sm">
            <thead><tr><th>Order</th><th>Type</th><th>Status</th><th>Attempts</th><th>Details</th><th>Time</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
                <tr v-for="sync in syncs.data" :key="sync.id">
                    <td><Link v-if="sync.order_code" :href="`/admin/orders/${sync.subject_id}`" class="font-bold underline">{{ sync.order_code }}</Link><span v-else>#{{ sync.subject_id }}</span></td>
                    <td class="whitespace-nowrap">{{ syncTypeLabels[sync.type] ?? sync.type }}</td>
                    <td><StatusBadge kind="sync" :status="sync.status" /></td>
                    <td class="tabular-nums">{{ sync.attempts }}</td>
                    <td class="min-w-56 text-xs">{{ sync.status === 'synced' ? 'Accepted by Erzap' : sync.last_error ?? '—' }}<span v-if="sync.next_attempt_at && sync.status === 'failed'" class="admin-muted block">Automatic retry {{ witaTime(sync.next_attempt_at) }}</span></td>
                    <td class="whitespace-nowrap text-xs">{{ witaTime(sync.synced_at ?? sync.created_at) }}</td>
                    <td><div class="admin-actions"><button v-if="sync.status !== 'synced'" type="button" class="admin-action" :disabled="retrying === sync.id" :aria-label="`Resend ${sync.order_code ?? sync.subject_id}`" @click="retry(sync)"><i class="fa-solid fa-rotate" :class="{ 'fa-spin': retrying === sync.id }" aria-hidden="true"></i>Resend</button></div></td>
                </tr>
            </tbody>
        </table>
        <p v-else class="m-0 py-8 text-center text-sm">{{ active ? 'No log entries match these filters.' : 'No transactions to send yet. The queue fills automatically when orders are paid.' }}</p>
    </div>
    <Pagination :links="syncs.links" />
</template>

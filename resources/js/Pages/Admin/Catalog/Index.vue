<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Pagination from '../../../Components/Admin/Pagination.vue';
import SearchInput from '../../../Components/Admin/SearchInput.vue';
import { useLiveFilters } from '../../../Composables/useLiveFilters';
import { confirmDialog } from '../../../Support/dialog';
import type { AdminProps, CatalogRecord, Paginated } from '../../../Types/admin';
defineOptions({ layout: AdminLayout });
const props = defineProps<{ resource: 'products' | 'outlets' | 'categories'; records: Paginated<CatalogRecord>; filters: { search?: string; status?: string; type?: string }; unpriced: number[] }>();
const page = usePage<AdminProps>();
const names = { products: 'Products', outlets: 'Outlets', categories: 'Categories' };
const singular = { products: 'product', outlets: 'outlet', categories: 'category' };
const hampers = computed(() => props.resource === 'products' && props.filters.type === 'hampers');
const { filters: live, loading, active, apply, reset } = useLiveFilters(() => `/admin/${props.resource}`, { search: props.filters.search ?? '', status: props.filters.status ?? '', type: props.filters.type ?? '' });
const rupiah = (price: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(price);
const toggleError = computed(() => (page.props.errors as Record<string, string>).is_active);

// Activate/deactivate straight from the list; the server rejects activating a product without a price.
const toggling = ref<number | null>(null);
const toggleHints = {
    products: ['The product can be chosen on the order form again.', 'The product can no longer be chosen on the order form.'],
    outlets: ['The outlet is operating again; it shows on the website if selected under Website content.', 'The outlet is hidden from the website and takes no PO.'],
    categories: ['Products in this category can be ordered again.', 'Products in this category are hidden from the order form and the Baked Goods cards.'],
};
async function toggle(record: CatalogRecord) {
    const verb = record.is_active ? 'Deactivate' : 'Activate';
    const ok = await confirmDialog({
        title: `${verb} ${record.name}${record.variant ? ` (${record.variant})` : ''}?`, confirmLabel: verb, tone: record.is_active ? 'danger' : 'primary',
        icon: record.is_active ? 'fa-toggle-off' : 'fa-toggle-on', message: toggleHints[props.resource][record.is_active ? 1 : 0],
    });
    if (!ok) return;
    router.post(`/admin/${props.resource}/${record.id}/toggle`, { is_active: !record.is_active }, {
        preserveScroll: true, preserveState: true, onStart: () => { toggling.value = record.id; }, onFinish: () => { toggling.value = null; },
    });
}

// Bulk: rows picked with checkboxes (kept while paging), or every row matching the current filters.
const selected = ref<number[]>([]);
const bulkBusy = ref(false);
const pageIds = computed(() => props.records.data.map(record => record.id));
const allOnPage = computed(() => pageIds.value.length > 0 && pageIds.value.every(id => selected.value.includes(id)));
const someOnPage = computed(() => !allOnPage.value && pageIds.value.some(id => selected.value.includes(id)));
function togglePage() {
    selected.value = allOnPage.value ? selected.value.filter(id => !pageIds.value.includes(id)) : [...new Set([...selected.value, ...pageIds.value])];
}
watch(() => [props.filters.search, props.filters.status, props.filters.type], () => { selected.value = []; });
const plural = computed(() => (hampers.value ? 'hampers' : props.resource));
// Products without a price are never activated; say so in the confirmation before anything happens.
function skipNote(activate: boolean, all: boolean) {
    if (!activate || props.resource !== 'products') return '';
    const skipped = all ? props.unpriced.length : selected.value.filter(id => props.unpriced.includes(id)).length;
    return skipped ? ` ${skipped} of them ${skipped === 1 ? 'has' : 'have'} no base price yet and will stay inactive.` : '';
}
async function bulk(activate: boolean, all: boolean) {
    const count = all ? props.records.total : selected.value.length;
    const scope = all ? (active.value ? `all ${count} ${plural.value} matching the filters` : `all ${count} ${plural.value}`) : `${count} selected ${count === 1 ? singular[props.resource] : plural.value}`;
    const ok = await confirmDialog({
        title: `${activate ? 'Activate' : 'Deactivate'} ${scope}?`, confirmLabel: activate ? 'Activate' : 'Deactivate', tone: activate ? 'primary' : 'danger',
        icon: activate ? 'fa-toggle-on' : 'fa-toggle-off',
        message: `${toggleHints[props.resource][activate ? 0 : 1]}${skipNote(activate, all)}`,
    });
    if (!ok) return;
    router.post(`/admin/${props.resource}/bulk-status`, { is_active: activate, all, ids: all ? [] : selected.value, filters: props.filters }, {
        preserveScroll: true, onStart: () => { bulkBusy.value = true; }, onFinish: () => { bulkBusy.value = false; }, onSuccess: () => { selected.value = []; },
    });
}
</script>
<template>
    <Head :title="`${hampers ? 'Hampers' : names[resource]} · Orlena`" />
    <div class="flex flex-wrap items-center justify-between gap-4"><h1 class="text-3xl font-bold">{{ hampers ? 'Hampers' : names[resource] }}</h1><div class="flex flex-wrap gap-2"><Link v-if="page.props.auth.can_manage && resource === 'products' && !hampers" href="/admin/products/import" class="admin-secondary"><i class="fa-solid fa-file-import" aria-hidden="true"></i>Import from Erzap</Link><Link v-if="page.props.auth.can_manage" :href="hampers ? '/admin/products/create?type=hampers' : `/admin/${resource}/create`" class="admin-primary"><i class="fa-solid fa-plus" aria-hidden="true"></i>Add {{ hampers ? 'hampers' : singular[resource] }}</Link></div></div>
    <form class="my-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
        <SearchInput id="search" v-model="live.search" label="Search by name" :loading="loading" />
        <div><label for="status" class="mb-2 block text-sm">Status</label><select id="status" v-model="live.status"><option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <div v-if="resource === 'products'"><label for="type" class="mb-2 block text-sm">Type</label><select id="type" v-model="live.type"><option value="">All</option><option value="regular">Regular products</option><option value="hampers">Hampers</option></select></div>
        <button v-if="active" type="button" class="admin-secondary" @click="reset"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset</button>
    </form>
    <p v-if="toggleError" role="alert" class="admin-alert admin-alert-error mb-4">{{ toggleError }}</p>
    <div v-if="page.props.auth.can_manage && records.total" class="mb-3 flex flex-wrap items-center gap-2 text-sm">
        <template v-if="selected.length">
            <span class="font-bold" role="status">{{ selected.length }} selected</span>
            <button type="button" class="admin-primary" :disabled="bulkBusy" @click="bulk(true, false)"><i class="fa-solid fa-toggle-on" aria-hidden="true"></i>Activate</button>
            <button type="button" class="admin-secondary admin-danger" :disabled="bulkBusy" @click="bulk(false, false)"><i class="fa-solid fa-toggle-off" aria-hidden="true"></i>Deactivate</button>
            <button type="button" class="admin-action" @click="selected = []">Clear selection</button>
        </template>
        <template v-else>
            <span class="admin-muted">Tick rows to change several at once, or:</span>
            <button type="button" class="admin-secondary" :disabled="bulkBusy" @click="bulk(true, true)"><i class="fa-solid fa-toggle-on" aria-hidden="true"></i>Activate all{{ active ? ' matching' : '' }} ({{ records.total }})</button>
            <button type="button" class="admin-secondary admin-danger" :disabled="bulkBusy" @click="bulk(false, true)"><i class="fa-solid fa-toggle-off" aria-hidden="true"></i>Deactivate all{{ active ? ' matching' : '' }} ({{ records.total }})</button>
        </template>
    </div>
    <div class="admin-card overflow-x-auto">
        <table v-if="records.data.length" class="w-full text-left text-sm">
            <thead><tr><th v-if="page.props.auth.can_manage" class="w-10"><input type="checkbox" :checked="allOnPage" :indeterminate="someOnPage" aria-label="Select all rows on this page" @change="togglePage"></th><th><span class="sr-only">Photo</span></th><th>Name</th><th v-if="resource === 'products'">Category</th><th v-if="resource === 'products'">Base price</th><th v-if="resource === 'outlets'">Address</th><th>Status</th><th v-if="page.props.auth.can_manage" class="text-right">Actions</th></tr></thead>
            <tbody>
                <tr v-for="record in records.data" :key="record.id" :class="{ 'bg-cream/60': selected.includes(record.id) }">
                    <td v-if="page.props.auth.can_manage"><input v-model="selected" type="checkbox" :value="record.id" :aria-label="`Select ${record.name}${record.variant ? ` (${record.variant})` : ''}`"></td>
                    <td class="w-20"><span class="flex h-12 w-16 items-center justify-center overflow-hidden rounded-lg bg-cream"><img v-if="record.image" :src="record.image" alt="" class="size-full object-cover" loading="lazy"><i v-else class="fa-solid fa-image text-chocolate/30" aria-hidden="true"></i></span></td>
                    <td class="font-bold">{{ record.name }}<span v-if="record.variant" class="ml-2 rounded-full bg-cream px-2 py-0.5 text-xs font-bold">{{ record.variant }}</span><span v-if="record.is_hamper" class="ml-2 rounded-full bg-rose/40 px-2 py-0.5 text-xs font-bold"><i class="fa-solid fa-gift" aria-hidden="true"></i> Hampers</span><span v-if="record.sku || record.code" class="mt-1 block text-xs font-normal text-chocolate/60">{{ record.sku || record.code }}</span></td>
                    <td v-if="resource === 'products'">{{ record.category?.name }}</td>
                    <td v-if="resource === 'products'" class="whitespace-nowrap">{{ record.price == null ? 'Not set' : rupiah(record.price) }}<span v-if="record.sale_starts_on || record.sale_ends_on" class="mt-1 block text-xs text-chocolate/60">On sale {{ record.sale_starts_on ?? '…' }} to {{ record.sale_ends_on ?? '…' }}</span></td>
                    <td v-if="resource === 'outlets'" class="min-w-56">{{ record.address }}</td>
                    <td><div class="flex flex-wrap gap-1.5"><span class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-bold" :class="record.is_active ? 'bg-matcha/15' : 'bg-almond'">{{ record.is_active ? 'Active' : 'Inactive' }}</span><span v-if="resource === 'outlets'" class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-bold" :class="record.accepts_preorder ? 'bg-light-blue/20' : 'bg-cream'">{{ record.accepts_preorder ? 'Takes PO' : 'No PO' }}</span><span v-if="resource !== 'products' && record.show_on_website" class="whitespace-nowrap rounded-full bg-cream px-3 py-1 text-xs font-bold" title="Set under Website content"><i class="fa-solid fa-globe" aria-hidden="true"></i> On website</span><span v-if="record.is_delivery_hub" class="whitespace-nowrap rounded-full bg-rose/40 px-3 py-1 text-xs font-bold">Delivery</span></div></td>
                    <td v-if="page.props.auth.can_manage"><div class="admin-actions">
                        <button type="button" class="admin-action" :class="{ 'admin-danger': record.is_active }" :disabled="toggling === record.id" :aria-label="`${record.is_active ? 'Deactivate' : 'Activate'} ${record.name}`" @click="toggle(record)"><i class="fa-solid" :class="record.is_active ? 'fa-toggle-off' : 'fa-toggle-on'" aria-hidden="true"></i>{{ record.is_active ? 'Deactivate' : 'Activate' }}</button>
                        <Link :href="`/admin/${resource}/${record.id}/edit`" class="admin-action" :aria-label="`Edit ${record.name}`"><i class="fa-solid fa-pen" aria-hidden="true"></i>Edit</Link>
                    </div></td>
                </tr>
            </tbody>
        </table>
        <p v-else class="m-0 py-8 text-center text-chocolate/65">{{ filters.search || filters.status || filters.type ? 'Nothing matches these filters.' : 'Nothing here yet. An Admin can add new entries.' }}</p>
    </div>
    <p class="mt-4 text-xs text-chocolate/60">{{ records.total }} {{ records.total === 1 ? 'entry' : 'entries' }}</p><Pagination :links="records.links" />
</template>

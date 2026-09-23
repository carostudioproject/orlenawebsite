<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Pagination from '../../../Components/Admin/Pagination.vue';
import SearchInput from '../../../Components/Admin/SearchInput.vue';
import { useLiveFilters } from '../../../Composables/useLiveFilters';
import { witaTime } from '../../../Support/orderStatus';
import type { Paginated } from '../../../Types/admin';
defineOptions({ layout: AdminLayout });
interface OutletRow { id: number; name: string; code: string; erzap_outlet_id: string | null }
interface ProductRow { id: number; name: string; variant: string | null; sku: string; is_active: boolean; category: { name: string } | null; erzap_product_id: string | null; erzap_variant_id: string | null; barcode: string | null; reference_stock: number | null; reference_stock_at: string | null }
const props = defineProps<{ outlets: OutletRow[]; products: Paginated<ProductRow>; filters: { search?: string; unmapped?: string } }>();
const { filters: live, loading, active, reset } = useLiveFilters(() => '/admin/integrations/mapping', { search: props.filters.search ?? '', unmapped: props.filters.unmapped ?? '' });

// One form for outlets and the products on this page; saving keeps the current page and filters.
const rows = () => ({
    outlets: props.outlets.map(outlet => ({ id: outlet.id, erzap_outlet_id: outlet.erzap_outlet_id ?? '' })),
    products: props.products.data.map(product => ({ id: product.id, erzap_product_id: product.erzap_product_id ?? '', erzap_variant_id: product.erzap_variant_id ?? '', barcode: product.barcode ?? '' })),
});
const form = useForm(rows());
watch(() => [props.outlets, props.products], () => { form.defaults(rows()); form.reset(); });
function save() { form.put('/admin/integrations/mapping', { preserveScroll: true }); }
</script>
<template>
    <Head title="Erzap mapping · Orlena" />
    <Link href="/admin/integrations" class="admin-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to integration</Link>
    <p class="admin-eyebrow mt-5">Erzap integration</p><h1 class="text-3xl font-bold">Outlet &amp; product mapping</h1>
    <p class="admin-muted mt-2 max-w-3xl text-sm">Enter the IDs from Erzap so transactions can be matched. A product needs either an Erzap product ID <strong>or</strong> a barcode. Reference stock from Erzap is information for staff only and never limits PO.</p>

    <form class="mt-6 space-y-6" @submit.prevent="save">
        <div v-if="form.hasErrors" role="alert" class="admin-alert admin-alert-error"><ul class="list-disc pl-5"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>
        <section class="admin-card overflow-x-auto" aria-labelledby="outlets-title">
            <h2 id="outlets-title" class="mb-4 text-xl">Outlet</h2>
            <table class="w-full text-left text-sm">
                <thead><tr><th>Outlet</th><th>Erzap outlet ID</th></tr></thead>
                <tbody><tr v-for="(outlet, index) in outlets" :key="outlet.id"><td><span class="font-bold">{{ outlet.name }}</span><span class="admin-muted block text-xs">{{ outlet.code }}</span></td><td><label :for="`outlet-${outlet.id}`" class="sr-only">Erzap ID for {{ outlet.name }}</label><input :id="`outlet-${outlet.id}`" v-model="form.outlets[index].erzap_outlet_id" maxlength="80" class="max-w-64" placeholder="Not set"></td></tr></tbody>
            </table>
        </section>

        <section class="admin-card" aria-labelledby="products-title">
            <h2 id="products-title" class="mb-4 text-xl">Products</h2>
            <div class="mb-4 flex flex-wrap items-end gap-3">
                <SearchInput id="search" v-model="live.search" label="Name, SKU or barcode" :loading="loading" />
                <label class="flex items-center gap-2 text-sm"><input v-model="live.unmapped" type="checkbox" true-value="1" false-value="">Only unmapped</label>
                <button v-if="active" type="button" class="admin-secondary" @click="reset"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset</button>
            </div>
            <p v-if="form.isDirty" class="admin-muted mb-3 text-xs">Save first before changing page or filters.</p>
            <div class="overflow-x-auto">
                <table v-if="products.data.length" class="w-full text-left text-sm">
                    <thead><tr><th>Product</th><th>Erzap product ID</th><th>Erzap variant ID</th><th>Barcode</th><th>Ref. stock</th></tr></thead>
                    <tbody>
                        <tr v-for="(product, index) in products.data" :key="product.id">
                            <td class="min-w-48"><span class="font-bold">{{ product.name }}</span><span v-if="product.variant" class="ml-1 rounded-full bg-cream px-2 py-0.5 text-xs font-bold">{{ product.variant }}</span><span class="admin-muted block text-xs">{{ product.sku }} · {{ product.category?.name }}</span></td>
                            <td><label :for="`p-${product.id}`" class="sr-only">Erzap product ID for {{ product.name }}</label><input :id="`p-${product.id}`" v-model="form.products[index].erzap_product_id" maxlength="80" class="min-w-32"></td>
                            <td><label :for="`v-${product.id}`" class="sr-only">Erzap variant ID for {{ product.name }}</label><input :id="`v-${product.id}`" v-model="form.products[index].erzap_variant_id" maxlength="80" class="min-w-28"></td>
                            <td><label :for="`b-${product.id}`" class="sr-only">Barcode for {{ product.name }}</label><input :id="`b-${product.id}`" v-model="form.products[index].barcode" maxlength="80" class="min-w-32"></td>
                            <td class="whitespace-nowrap tabular-nums">{{ product.reference_stock ?? '—' }}<span v-if="product.reference_stock_at" class="admin-muted block text-xs">{{ witaTime(product.reference_stock_at) }}</span></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="m-0 py-6 text-center text-sm">No matching products.</p>
            </div>
            <Pagination :links="products.links" />
        </section>

        <div class="flex flex-wrap gap-3"><button class="admin-primary" :disabled="form.processing || !form.isDirty"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ form.processing ? 'Saving…' : 'Save mapping' }}</button></div>
    </form>
</template>

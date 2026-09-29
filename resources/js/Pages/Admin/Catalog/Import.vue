<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
defineOptions({ layout: AdminLayout });

type Field = 'barcode' | 'sku' | 'name' | 'price' | 'category' | 'variant' | 'hamper' | 'hamper_contents';
type Action = 'create' | 'update' | 'skip';
interface Row {
    line: number; action: Action; name: string | null; variant: string | null; barcode: string | null; sku: string | null; price: number | null; category: string | null; hamper?: boolean; hamper_contents?: string | null;
    changes?: Partial<Record<'name' | 'price' | 'barcode' | 'sku', [string | number | null, string | number | null]>>;
    current?: string; matched_by?: string; reason?: string; warning?: string | null;
}
interface Upload {
    token: string; file: string; headers: string[]; mapping: Record<Field, number | null>; update_names: boolean; errors: string[];
    summary: { create: number; update: number; unchanged: number; skip: number }; rows: Row[];
}
const props = defineProps<{ fields: Record<Field, string>; upload: Upload | null }>();
const page = usePage();
const pageErrors = computed(() => page.props.errors as Record<string, string>);

const uploadForm = useForm<{ file: File | null }>({ file: null });
function pick(event: Event) { uploadForm.file = (event.target as HTMLInputElement).files?.[0] ?? null; }
function send() { uploadForm.post('/admin/products/import', { forceFormData: true }); }

const mappingForm = useForm({ token: props.upload?.token ?? '', mapping: { ...(props.upload?.mapping ?? {}) } as Record<string, number | null>, update_names: props.upload?.update_names ?? false });
watch(() => props.upload, upload => { mappingForm.token = upload?.token ?? ''; mappingForm.mapping = { ...(upload?.mapping ?? {}) }; mappingForm.update_names = upload?.update_names ?? false; mappingForm.defaults(); });
function remap() { mappingForm.post('/admin/products/import/mapping', { preserveScroll: true }); }

const applyForm = useForm({ token: props.upload?.token ?? '' });
function apply() { applyForm.token = props.upload?.token ?? ''; applyForm.post('/admin/products/import/apply'); }

const tab = ref<Action>('update');
const tabs = computed(() => ([
    { value: 'update', label: 'Updated', count: props.upload?.summary.update ?? 0 },
    { value: 'create', label: 'New', count: props.upload?.summary.create ?? 0 },
    { value: 'skip', label: 'Skipped', count: props.upload?.summary.skip ?? 0 },
] as const));
const visible = computed(() => (props.upload?.rows ?? []).filter(row => row.action === tab.value));
const canApply = computed(() => !!props.upload && !props.upload.errors.length && (props.upload.summary.create + props.upload.summary.update) > 0 && !mappingForm.isDirty);
const rupiah = (value: string | number | null | undefined) => value == null || value === '' ? '—' : new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value));
const show = (field: string, value: string | number | null | undefined) => field === 'price' ? rupiah(value) : (value ?? '—');
const fieldLabels: Record<string, string> = { name: 'Name', price: 'Price', barcode: 'Barcode', sku: 'Code' };
</script>
<template>
    <Head title="Import from Erzap · Orlena" />
    <Link href="/admin/products" class="admin-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to products</Link>
    <p class="admin-eyebrow mt-5">Products</p><h1 class="text-3xl font-bold">Import from Erzap</h1>
    <div class="admin-muted mt-2 max-w-3xl space-y-1 text-sm">
        <p class="m-0">Export the product list from Erzap as Excel (.xlsx) or CSV and upload it here. You see every change before anything is saved.</p>
        <p class="m-0"><strong>Updated from Erzap:</strong> selling price, barcode and product code (names only when you tick the option below the columns). <strong>Never changed:</strong> photo, description, category and size of existing products, active status, hampers contents, sale period and outlet prices.</p>
        <p class="m-0">New products are added <strong>inactive</strong>: add a photo and check them, then activate them in Products.</p>
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-3">
        <a href="/admin/products/import/template" class="admin-secondary"><i class="fa-solid fa-file-excel" aria-hidden="true"></i>Download Excel format</a>
        <span class="admin-muted text-xs">Filled with the current products; add the Erzap code and barcode, then upload it here.</span>
    </div>

    <form class="admin-card mt-6 flex flex-wrap items-end gap-3" @submit.prevent="send">
        <div class="min-w-64 flex-1">
            <label for="file" class="mb-2 block text-sm font-bold">Erzap export file</label>
            <input id="file" type="file" accept=".xlsx,.csv" required :aria-invalid="!!uploadForm.errors.file" aria-describedby="file-help" @change="pick">
            <p id="file-help" class="admin-muted mt-1 text-xs">.xlsx or .csv, up to 5 MB. The first row must contain the column names.</p>
            <p v-if="uploadForm.errors.file" role="alert" class="admin-error-text mt-1 text-sm">{{ uploadForm.errors.file }}</p>
        </div>
        <button type="submit" class="admin-primary" :disabled="!uploadForm.file || uploadForm.processing"><i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i>{{ uploadForm.processing ? 'Reading…' : upload ? 'Upload another file' : 'Upload and preview' }}</button>
    </form>

    <template v-if="upload">
        <section class="admin-card mt-6" aria-labelledby="columns-title">
            <h2 id="columns-title" class="text-xl">Columns in {{ upload.file }}</h2>
            <p class="admin-muted mt-1 text-sm">Detected from the column names. Correct them if a field points to the wrong column.</p>
            <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="remap">
                <div v-for="(label, field) in fields" :key="field">
                    <label :for="`map-${field}`" class="mb-1 block text-sm font-bold">{{ label }}<span v-if="field === 'name'" class="admin-muted font-normal"> (required)</span></label>
                    <select :id="`map-${field}`" v-model="mappingForm.mapping[field]" class="w-full">
                        <option :value="null">Not in file</option>
                        <option v-for="(header, index) in upload.headers" :key="index" :value="index">{{ header || `Column ${index + 1}` }}</option>
                    </select>
                </div>
                <label class="flex items-start gap-2 text-sm sm:col-span-2 lg:col-span-3"><input v-model="mappingForm.update_names" type="checkbox" class="mt-1"><span><strong>Also update names of existing products</strong><span class="admin-muted block text-xs">Leave off when Erzap puts the size in the name (for example "Half Bluberry Cheese"): the order form groups Fullsize and Halfsize under one name.</span></span></label>
                <div class="flex items-end sm:col-span-2 lg:col-span-3"><button type="submit" class="admin-secondary" :disabled="!mappingForm.isDirty || mappingForm.processing"><i class="fa-solid fa-rotate" aria-hidden="true"></i>Update preview</button></div>
            </form>
            <ul v-if="upload.errors.length || pageErrors.mapping" role="alert" class="admin-alert admin-alert-error mt-4 list-disc pl-8">
                <li v-for="error in upload.errors" :key="error">{{ error }}</li><li v-if="pageErrors.mapping">{{ pageErrors.mapping }}</li>
            </ul>
        </section>

        <section class="admin-card mt-6" aria-labelledby="preview-title">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="preview-title" class="text-xl">Preview</h2>
                <p class="admin-muted m-0 text-sm">{{ upload.summary.update }} updated · {{ upload.summary.create }} new · {{ upload.summary.unchanged }} unchanged · {{ upload.summary.skip }} skipped</p>
            </div>
            <div class="admin-segment mt-4" role="group" aria-label="Show rows">
                <button v-for="item in tabs" :key="item.value" type="button" :aria-pressed="tab === item.value" @click="tab = item.value">{{ item.label }} ({{ item.count }})</button>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table v-if="visible.length" class="w-full text-left text-sm">
                    <thead v-if="tab === 'update'"><tr><th>Line</th><th>Website product</th><th>Changes</th></tr></thead>
                    <thead v-else-if="tab === 'create'"><tr><th>Line</th><th>Product</th><th>Category</th><th>Barcode / code</th><th>Price</th></tr></thead>
                    <thead v-else><tr><th>Line</th><th>Row</th><th>Reason</th></tr></thead>
                    <tbody>
                        <tr v-for="row in visible" :key="row.line">
                            <td class="tabular-nums">{{ row.line }}</td>
                            <template v-if="tab === 'update'">
                                <td class="min-w-48"><span class="font-bold">{{ row.current }}</span><span class="admin-muted block text-xs">Matched by {{ row.matched_by }}</span><span v-if="row.warning" class="admin-error-text block text-xs">{{ row.warning }}</span></td>
                                <td><ul class="m-0 list-none space-y-1 p-0"><li v-for="(change, field) in row.changes" :key="field"><span class="font-bold">{{ fieldLabels[field] }}:</span> <del class="admin-muted">{{ show(field, change?.[0]) }}</del> <i class="fa-solid fa-arrow-right text-xs" aria-label="to"></i> {{ show(field, change?.[1]) }}</li></ul></td>
                            </template>
                            <template v-else-if="tab === 'create'">
                                <td class="min-w-48"><span class="font-bold">{{ row.name }}</span><span v-if="row.variant" class="ml-1 rounded-full bg-cream px-2 py-0.5 text-xs font-bold">{{ row.variant }}</span><span v-if="row.hamper || row.hamper_contents" class="ml-1 rounded-full bg-rose/40 px-2 py-0.5 text-xs font-bold"><i class="fa-solid fa-gift" aria-hidden="true"></i> Hampers</span><span v-if="row.warning" class="admin-error-text block text-xs">{{ row.warning }}</span></td>
                                <td>{{ row.category ?? 'Imported from Erzap' }}</td>
                                <td class="text-xs">{{ row.barcode ?? '—' }}<span class="admin-muted block">{{ row.sku }}</span></td>
                                <td class="whitespace-nowrap">{{ rupiah(row.price) }}</td>
                            </template>
                            <template v-else>
                                <td>{{ row.name ?? '—' }}<span class="admin-muted block text-xs">{{ row.barcode ?? row.sku }}</span></td><td>{{ row.reason }}</td>
                            </template>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="m-0 py-6 text-center text-sm">Nothing in this group.</p>
            </div>
        </section>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <button type="button" class="admin-primary" :disabled="!canApply || applyForm.processing" @click="apply"><i class="fa-solid fa-check" aria-hidden="true"></i>{{ applyForm.processing ? 'Importing…' : `Apply ${upload.summary.update + upload.summary.create} changes` }}</button>
            <p v-if="mappingForm.isDirty" class="admin-muted m-0 text-sm">Update the preview first.</p>
            <p v-else-if="!upload.summary.update && !upload.summary.create" class="admin-muted m-0 text-sm">Everything already matches Erzap.</p>
        </div>
    </template>
</template>

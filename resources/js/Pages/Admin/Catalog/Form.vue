<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Field from '../../../Components/Field.vue';
import ImageInput from '../../../Components/Admin/ImageInput.vue';
import type { CatalogRecord } from '../../../Types/admin';
defineOptions({ layout: AdminLayout });
interface Candidate { id: number; name: string; variant: string | null; sku: string; price: number | null; is_active: boolean; category: string | null }
const props = defineProps<{ resource: 'products' | 'outlets' | 'categories'; record: CatalogRecord | null; categories: { id: number; name: string }[]; outlets: { id: number; name: string }[]; hamperMode?: boolean; contentCandidates?: Candidate[] }>();
const names = { products: 'product', outlets: 'outlet', categories: 'category' };
// Hampers are products; opened from the Hampers list the form is titled and pre-set as a hamper.
const noun = computed(() => (props.hamperMode ? 'hampers' : names[props.resource]));
const listUrl = computed(() => (props.hamperMode ? '/admin/products?type=hampers' : `/admin/${props.resource}`));
const form = useForm({
    name: props.record?.name ?? '', sku: props.record?.sku ?? '', barcode: props.record?.barcode ?? '', code: props.record?.code ?? '',
    category_id: props.record?.category_id ?? '', variant: props.record?.variant ?? '', description: props.record?.description ?? '', price: props.record?.price ?? '',
    address: props.record?.address ?? '', maps_url: props.record?.maps_url ?? '',
    // New outlets and categories start active (outlets also take PO); new products stay inactive until priced and reviewed.
    is_active: props.record?.is_active ?? props.resource !== 'products', accepts_preorder: props.record?.accepts_preorder ?? true, is_delivery_hub: props.record?.is_delivery_hub ?? false,
    is_hamper: props.record?.is_hamper ?? false, hamper_contents: props.record?.hamper_contents ?? '',
    // New hampers default to "customer chooses 1 item"; fixed packages use the hampers contents text instead.
    content_type: (props.record ? props.record.content_type ?? '' : props.hamperMode ? 'hampers' : '') as '' | 'hampers' | 'box',
    content_count: props.record?.content_count ?? 1, content_pricing: props.record?.content_pricing ?? 'added',
    content_ids: [...(props.record?.content_ids ?? [])] as number[],
    sale_starts_on: props.record?.sale_starts_on ?? '', sale_ends_on: props.record?.sale_ends_on ?? '',
    order_position: props.record?.order_position ?? 100,
    upload: null as File | null,
    outlet_prices: (props.record?.outlet_prices ?? []).map(p => ({ outlet_id: p.outlet_id as number | string, price: p.price as number | string })),
});
// Hampers hold one item whose price is added; a box holds N items priced per box.
watch(() => form.content_type, type => {
    if (type === 'hampers') { form.content_count = 1; form.content_pricing = 'added'; }
    if (type === 'box') { form.content_count = Math.max(form.content_count, 2); form.content_pricing = 'included'; }
});
const contentSearch = ref('');
const sameSize = ref(!!props.record?.variant && form.content_type === 'hampers');
const candidates = computed(() => (props.contentCandidates ?? []).filter(item => {
    if (form.content_ids.includes(item.id)) return true;
    if (sameSize.value && form.variant && (item.variant ?? '').toLowerCase() !== form.variant.toLowerCase()) return false;
    const search = contentSearch.value.trim().toLowerCase();
    return !search || `${item.name} ${item.variant ?? ''} ${item.sku} ${item.category ?? ''}`.toLowerCase().includes(search);
}));
function toggleAll(on: boolean) {
    const ids = candidates.value.map(item => item.id);
    form.content_ids = on ? [...new Set([...form.content_ids, ...ids])] : form.content_ids.filter(id => !ids.includes(id));
}
function submit() {
    form.transform(data => ({ ...data, content_type: data.content_type || null }));
    // Method spoofing keeps file uploads working on update (PHP does not parse multipart PUT bodies).
    if (props.record) form.transform(data => ({ ...data, content_type: data.content_type || null, _method: 'put' })).post(`/admin/${props.resource}/${props.record.id}`);
    else form.post(`/admin/${props.resource}`);
}
</script>
<template>
    <Head :title="`${record ? 'Edit' : 'Add'} ${noun} · Orlena`" />
    <Link :href="listUrl" class="admin-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to list</Link>
    <h1 class="my-5 text-3xl font-bold">{{ record ? 'Edit' : 'Add' }} {{ noun }}</h1>
    <form class="admin-card max-w-3xl space-y-6" @submit.prevent="submit">
        <div v-if="Object.keys(form.errors).length" role="alert" class="admin-alert admin-alert-error"><p class="font-bold">Please check the following:</p><ul class="list-disc pl-5"><li v-for="(error,key) in form.errors" :key="key">{{ error }}</li></ul></div>
        <Field id="name" label="Name" :error="form.errors.name"><template #default="{ describedBy }"><input id="name" v-model="form.name" required maxlength="160" :aria-describedby="describedBy" :aria-invalid="!!form.errors.name"></template></Field>
        <template v-if="resource === 'products'">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2"><Field id="sku" label="Product code (Kode Produk in Erzap)" :error="form.errors.sku"><template #default="{ describedBy }"><input id="sku" v-model="form.sku" required maxlength="80" :aria-describedby="describedBy" :aria-invalid="!!form.errors.sku"></template></Field><Field id="category_id" label="Category" :error="form.errors.category_id"><template #default="{ describedBy }"><select id="category_id" v-model="form.category_id" required :aria-describedby="describedBy" :aria-invalid="!!form.errors.category_id"><option value="" disabled>Choose a category</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option></select></template></Field></div>
            <p v-if="!categories.length" class="text-sm">Add a category first under Categories.</p>
            <Field id="barcode" label="Barcode (from Erzap)" hint="Must be exactly the barcode of this product and size in Erzap; orders reach Erzap by barcode. Leave empty only for products not sent to Erzap." :error="form.errors.barcode"><template #default="{ describedBy }"><input id="barcode" v-model.trim="form.barcode" maxlength="80" inputmode="numeric" autocomplete="off" class="font-mono" :aria-describedby="describedBy" :aria-invalid="!!form.errors.barcode"></template></Field>
            <Field id="variant" label="Variant (optional)" hint="E.g. Fullsize, Halfsize (20x10), Box 2x2. Each size is its own product (own card, photo, price and Erzap barcode)." :error="form.errors.variant"><template #default="{ describedBy }"><input id="variant" v-model="form.variant" maxlength="40" list="variant-suggestions" placeholder="Leave empty if there is only one kind" :aria-describedby="describedBy" :aria-invalid="!!form.errors.variant"><datalist id="variant-suggestions"><option value="Fullsize" /><option value="Halfsize" /></datalist></template></Field>
            <Field id="description" label="Description" :error="form.errors.description"><template #default="{ describedBy }"><textarea id="description" v-model="form.description" rows="4" maxlength="5000" :aria-describedby="describedBy"></textarea></template></Field>
            <Field id="price" label="Base price (Rp)" :error="form.errors.price"><template #default="{ describedBy }"><input id="price" v-model="form.price" type="number" min="1" max="999999999" step="1" :required="form.is_active" :aria-describedby="describedBy" :aria-invalid="!!form.errors.price"></template></Field>
            <fieldset class="space-y-4 rounded-xl border border-chocolate/15 p-4">
                <legend class="px-2 text-sm font-bold">Contents and sale period</legend>
                <Field id="content_type" label="Type" :error="form.errors.content_type"><template #default="{ describedBy }"><select id="content_type" v-model="form.content_type" :aria-describedby="describedBy"><option value="">Regular product (fixed contents)</option><option value="hampers">Hampers: the customer chooses the item inside</option><option value="box">Box: the customer chooses several items (e.g. Mini Size 2x2)</option></select></template></Field>
                <template v-if="form.content_type">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field id="content_count" label="Items per unit" :hint="form.content_type === 'hampers' ? 'Hampers always hold 1 item.' : 'E.g. 4 for 2x2, 9 for 3x3. The same item may be chosen more than once.'" :error="form.errors.content_count"><template #default="{ describedBy }"><input id="content_count" v-model.number="form.content_count" type="number" min="1" max="50" required :disabled="form.content_type === 'hampers'" :aria-describedby="describedBy"></template></Field>
                        <Field id="content_pricing" label="Price of the contents" :error="form.errors.content_pricing"><template #default="{ describedBy }"><select id="content_pricing" v-model="form.content_pricing" :aria-describedby="describedBy"><option value="added">Added: hampers price + the item's normal price</option><option value="included">Included: Rp 0 (the price is per box)</option></select></template></Field>
                    </div>
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <p class="m-0 text-sm font-bold">Can be chosen as contents <span class="admin-muted font-normal">({{ form.content_ids.length }} selected)</span></p>
                            <div class="flex flex-wrap items-center gap-3 text-xs">
                                <label v-if="form.variant" class="flex items-center gap-1.5"><input v-model="sameSize" type="checkbox">Only size "{{ form.variant }}"</label>
                                <button type="button" class="underline" @click="toggleAll(true)">Select shown</button><button type="button" class="underline" @click="toggleAll(false)">Clear shown</button>
                            </div>
                        </div>
                        <input v-model="contentSearch" type="search" placeholder="Search name, size or code" aria-label="Search products to use as contents">
                        <div class="max-h-72 space-y-1 overflow-y-auto rounded-xl border border-chocolate/15 p-2" role="group" aria-label="Products that can be chosen as contents">
                            <label v-for="item in candidates" :key="item.id" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-cream">
                                <input v-model="form.content_ids" type="checkbox" :value="item.id">
                                <span class="flex-1">{{ item.name }}<span v-if="item.variant" class="admin-muted"> · {{ item.variant }}</span> <span class="admin-muted text-xs">{{ item.sku }}</span></span>
                                <span class="text-xs tabular-nums" :class="{ 'admin-muted': form.content_pricing === 'included' }">{{ item.price ? `Rp ${item.price.toLocaleString('id-ID')}` : 'no price' }}</span>
                                <span v-if="!item.is_active && form.content_pricing === 'added'" class="text-xs admin-error-text">inactive</span>
                            </label>
                            <p v-if="!candidates.length" class="admin-muted m-0 p-2 text-sm">No products match.</p>
                        </div>
                        <p v-if="form.errors.content_ids" class="admin-error-text m-0 text-sm" role="alert">{{ form.errors.content_ids }}</p>
                        <p class="admin-muted m-0 text-xs">{{ form.content_pricing === 'added' ? 'Only active products with a price are offered to customers. ' : '' }}New products are not added automatically; tick them here.</p>
                    </div>
                </template>
                <label class="flex items-start gap-3 text-sm"><input v-model="form.is_hamper" type="checkbox" class="mt-0.5"><span><span class="block font-bold">Fixed hampers package</span><span class="admin-muted text-xs">For a package with set contents listed below (and the optional greeting card on the order form).</span></span></label>
                <Field v-if="form.is_hamper" id="hamper_contents" label="Package contents" hint="One item per line, e.g. 1x Brownies Berry Halfsize." :error="form.errors.hamper_contents"><template #default="{ describedBy }"><textarea id="hamper_contents" v-model="form.hamper_contents" rows="4" maxlength="3000" :required="!form.content_type" :aria-describedby="describedBy" :aria-invalid="!!form.errors.hamper_contents"></textarea></template></Field>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field id="sale_starts_on" label="On sale from (optional)" :error="form.errors.sale_starts_on"><template #default="{ describedBy }"><input id="sale_starts_on" v-model="form.sale_starts_on" type="date" :aria-describedby="describedBy"></template></Field>
                    <Field id="sale_ends_on" label="On sale until (optional)" :error="form.errors.sale_ends_on"><template #default="{ describedBy }"><input id="sale_ends_on" v-model="form.sale_ends_on" type="date" :min="form.sale_starts_on || undefined" :aria-describedby="describedBy" :aria-invalid="!!form.errors.sale_ends_on"></template></Field>
                </div>
                <p class="admin-muted m-0 text-xs">For event products (e.g. Lebaran hampers). Outside this period the product is hidden from the order form even when active. Leave empty to sell all year.</p>
            </fieldset>
            <fieldset class="space-y-3 rounded-xl border border-chocolate/15 p-4"><legend class="px-2 text-sm font-bold">Outlet prices (optional)</legend><p class="text-sm text-chocolate/65">Outlets without their own price use the base price.</p><div v-for="(price,index) in form.outlet_prices" :key="index" class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr_auto]"><div><label :for="`outlet-${index}`" class="mb-1 block text-xs">Outlet</label><select :id="`outlet-${index}`" v-model="price.outlet_id" required><option value="" disabled>Choose an outlet</option><option v-for="outlet in outlets" :key="outlet.id" :value="outlet.id">{{ outlet.name }}</option></select></div><div><label :for="`outlet-price-${index}`" class="mb-1 block text-xs">Price (Rp)</label><input :id="`outlet-price-${index}`" v-model="price.price" type="number" min="1" max="999999999" step="1" required></div><button type="button" class="admin-secondary self-end" :aria-label="`Remove outlet price ${index + 1}`" @click="form.outlet_prices.splice(index,1)"><i class="fa-solid fa-trash-can" aria-hidden="true"></i>Remove</button></div><button type="button" class="admin-secondary" :disabled="!outlets.length || form.outlet_prices.length >= outlets.length" @click="form.outlet_prices.push({ outlet_id: '', price: '' })">Add outlet price</button></fieldset>
        </template>
        <template v-if="resource === 'outlets'">
            <Field id="code" label="Outlet code" :error="form.errors.code"><template #default="{ describedBy }"><input id="code" v-model="form.code" required maxlength="80" :aria-describedby="describedBy" :aria-invalid="!!form.errors.code"></template></Field>
            <Field id="address" label="Address" :error="form.errors.address"><template #default="{ describedBy }"><textarea id="address" v-model="form.address" rows="3" required maxlength="2000" :aria-describedby="describedBy" :aria-invalid="!!form.errors.address"></textarea></template></Field>
            <Field id="maps_url" label="Google Maps link (optional)" :error="form.errors.maps_url"><template #default="{ describedBy }"><input id="maps_url" v-model="form.maps_url" type="url" maxlength="1000" :aria-describedby="describedBy" :aria-invalid="!!form.errors.maps_url"></template></Field>
        </template>
        <Field v-if="resource === 'categories'" id="order_position" label="Order on the order form" hint="Lower numbers come first in the category chips on /order, e.g. 1 for Brownies. Categories with the same number are sorted by name." :error="form.errors.order_position"><template #default="{ describedBy }"><input id="order_position" v-model.number="form.order_position" type="number" min="0" max="999" required class="max-w-32" :aria-describedby="describedBy"></template></Field>
        <ImageInput id="photo" :label="{ products: 'Product photo', outlets: 'Outlet photo', categories: 'Category photo' }[resource]" :current="record?.image ?? null" :error="form.errors.upload" @select="file => (form.upload = file)" />
        <fieldset class="space-y-3 rounded-xl border border-chocolate/15 p-4">
            <legend class="px-2 text-sm font-bold">Status</legend>
            <label class="flex items-start gap-3 text-sm"><input v-model="form.is_active" type="checkbox" class="mt-0.5"><span><span class="block font-bold">Active</span><span class="admin-muted text-xs">{{ { products: 'The product can be chosen on the PO form.', outlets: 'The outlet is operating. Showing it on the website is set under Website content → Outlet order.', categories: 'Products in this category can be ordered. Showing it in Baked Goods is set under Website content.' }[resource] }}</span></span></label>
            <label v-if="resource === 'outlets'" class="flex items-start gap-3 text-sm"><input v-model="form.is_delivery_hub" type="checkbox" class="mt-0.5"><span><span class="block font-bold">Delivery outlet</span><span class="admin-muted text-xs">All delivery (Gojek/Grab) orders ship from this outlet. Only one outlet; choosing this one replaces the previous one.</span></span></label>
            <label v-if="resource === 'outlets'" class="flex items-start gap-3 text-sm"><input v-model="form.accepts_preorder" type="checkbox" class="mt-0.5"><span><span class="block font-bold">Takes pre-orders</span><span class="admin-muted text-xs">The outlet appears as a choice on the pre-order form (only while active).</span></span></label>
        </fieldset>
        <p v-if="resource === 'products'" class="text-sm text-chocolate/60">A price is required before activating a product. Staff still check availability and production capacity before payment.</p>
        <div class="flex flex-wrap gap-3"><button class="admin-primary" :disabled="form.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ form.processing ? 'Saving…' : 'Save' }}</button><Link :href="listUrl" class="admin-secondary"><i class="fa-solid fa-xmark" aria-hidden="true"></i>Cancel</Link></div>
    </form>
</template>

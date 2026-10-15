<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Field from '../../../Components/Field.vue';
import ImageInput from '../../../Components/Admin/ImageInput.vue';
import type { CatalogRecord } from '../../../Types/admin';
defineOptions({ layout: AdminLayout });
interface Candidate { id: number; name: string; variant: string | null; sku: string; price: number | null; is_active: boolean; flavour: boolean; category: string | null }
const props = defineProps<{ resource: 'products' | 'outlets' | 'categories'; record: CatalogRecord | null; categories: { id: number; name: string }[]; outlets: { id: number; name: string }[]; hamperMode?: boolean; contentCandidates?: Candidate[];
    addonCandidates?: { id: number; name: string; variant: string | null; price: number | null; is_active: boolean; category: string | null }[] }>();
const names = { products: 'product', outlets: 'outlet', categories: 'category' };
// Hampers are products; opened from the Hampers list the form is titled and pre-set as a hamper.
const noun = computed(() => (props.hamperMode ? 'hampers' : names[props.resource]));
const listUrl = computed(() => (props.hamperMode ? '/admin/products?type=hampers' : `/admin/${props.resource}`));
const form = useForm({
    name: props.record?.name ?? '', sku: props.record?.sku ?? '', barcode: props.record?.barcode ?? '', code: props.record?.code ?? '',
    category_id: props.record?.category_id ?? '', variant: props.record?.variant ?? '', description: props.record?.description ?? '', price: props.record?.price ?? '',
    address: props.record?.address ?? '', maps_url: props.record?.maps_url ?? '',
    // New outlets and categories start active (outlets also take PO); new products stay inactive until priced and reviewed.
    is_active: props.record?.is_active ?? props.resource !== 'products', accepts_preorder: props.record?.accepts_preorder ?? true, delivers: props.record?.delivers ?? false,
    // Rules Order Website: 100 orders a day (30 at malls), 20 pickups an hour, slots from 13.00 until an hour before closing.
    daily_quota: props.record?.daily_quota ?? 100, hourly_quota: props.record?.hourly_quota ?? 20,
    first_slot: props.record?.first_slot?.slice(0, 5) ?? '13:00', closes_at: props.record?.closes_at?.slice(0, 5) ?? '21:00',
    is_hamper: props.record?.is_hamper ?? false, hamper_contents: props.record?.hamper_contents ?? '',
    // New hampers default to "customer chooses 1 item"; fixed packages use the hampers contents text instead.
    content_type: (props.record ? props.record.content_type ?? '' : props.hamperMode ? 'hampers' : '') as '' | 'hampers' | 'box' | 'set' | 'flavour',
    content_count: props.record?.content_count ?? 1, content_pricing: props.record?.content_pricing ?? 'added',
    content_ids: [...(props.record?.content_ids ?? [])] as number[],
    addon_ids: [...(props.record?.addon_ids ?? [])] as number[],
    sale_starts_on: props.record?.sale_starts_on ?? '', sale_ends_on: props.record?.sale_ends_on ?? '',
    order_position: props.record?.order_position ?? 100,
    upload: null as File | null,
    outlet_prices: (props.record?.outlet_prices ?? []).map(p => ({ outlet_id: p.outlet_id as number | string, price: p.price as number | string })),
});
/**
 * What kind of product this is, chosen first:
 * regular (sold on its own), hampers (customer picks 1 item, price added), box (customer picks N items, price per box),
 * set (a box with flavours fixed by Orlena), or content (only sold inside a box, e.g. a Mini Size flavour: Rp 0, never its own card).
 */
type Kind = 'regular' | 'hampers' | 'box' | 'set' | 'content';
const typeOf: Record<Kind, typeof form.content_type> = { regular: '', hampers: 'hampers', box: 'box', set: 'set', content: 'flavour' };
const kind = ref<Kind>(form.content_type === 'flavour' ? 'content' : form.content_type || 'regular');
const kinds: { value: Kind; label: string; hint: string; icon: string }[] = [
    { value: 'regular', label: 'Regular product', hint: 'Sold on its own, e.g. Blueberry Cheese Fullsize.', icon: 'fa-cookie-bite' },
    { value: 'hampers', label: 'Hampers', hint: 'The customer picks 1 product inside; its price is added.', icon: 'fa-gift' },
    { value: 'box', label: 'Box (Mix n Match)', hint: 'The customer picks several items, e.g. 4 Mini flavours; one price per box.', icon: 'fa-box-open' },
    { value: 'set', label: 'Box with fixed flavours', hint: 'Orlena sets the flavours, e.g. 2 Almond + 2 Choco; the customer just adds it.', icon: 'fa-box' },
    { value: 'content', label: 'Box flavour only', hint: 'E.g. Mini Size Almond: Rp 0, only chosen inside a box.', icon: 'fa-layer-group' },
];
watch(kind, (value, previous) => {
    form.content_type = typeOf[value];
    if (value === 'content') { form.price = 0; form.is_active = false; }
    // Hampers hold regular products; boxes hold box flavours: a list from the other kind no longer applies.
    if ((value === 'hampers') !== (previous === 'hampers')) form.content_ids = [];
});
// Box flavours for a box or fixed box; regular products for hampers.
const flavourPool = computed(() => form.content_type === 'box' || form.content_type === 'set');
/** Pieces of each flavour in a fixed box: the id is repeated once per piece. */
const pieces = (id: number) => form.content_ids.filter(item => item === id).length;
function setPieces(id: number, step: number) {
    if (step > 0) form.content_ids.push(id);
    else if (pieces(id)) form.content_ids.splice(form.content_ids.lastIndexOf(id), 1);
}
// Hampers hold one item whose price is added; a box holds N items priced per box.
watch(() => form.content_type, type => {
    if (type === 'hampers') { form.content_count = 1; form.content_pricing = 'added'; }
    if (type === 'box') { form.content_count = Math.max(form.content_count, 2); form.content_pricing = 'included'; }
});
const contentSearch = ref('');
const sameSize = ref(!!props.record?.variant && form.content_type === 'hampers');
const candidates = computed(() => (props.contentCandidates ?? []).filter(item => {
    if (form.content_ids.includes(item.id)) return true;
    if (item.flavour !== flavourPool.value) return false;
    if (sameSize.value && form.content_type === 'hampers' && form.variant && (item.variant ?? '').toLowerCase() !== form.variant.toLowerCase()) return false;
    const search = contentSearch.value.trim().toLowerCase();
    return !search || `${item.name} ${item.variant ?? ''} ${item.sku} ${item.category ?? ''}`.toLowerCase().includes(search);
}));
function toggleAll(on: boolean) {
    const ids = candidates.value.map(item => item.id);
    form.content_ids = on ? [...new Set([...form.content_ids, ...ids])] : form.content_ids.filter(id => !ids.includes(id));
}
// Category extras: chosen ones first, then packaging, filtered by a search.
const addonSearch = ref('');
const addonList = computed(() => {
    const query = addonSearch.value.trim().toLowerCase();
    return (props.addonCandidates ?? []).filter(item => !query || `${item.name} ${item.variant ?? ''} ${item.category ?? ''}`.toLowerCase().includes(query))
        .sort((a, b) => Number(form.addon_ids.includes(b.id)) - Number(form.addon_ids.includes(a.id)));
});
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
            <fieldset class="space-y-2">
                <legend class="mb-2 text-sm font-bold">Product type</legend>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <label v-for="option in kinds" :key="option.value" class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm transition" :class="kind === option.value ? 'border-chocolate bg-cream' : 'border-chocolate/15 hover:border-chocolate/40'">
                        <input v-model="kind" type="radio" name="product-kind" :value="option.value" class="mt-1">
                        <span><span class="block font-bold"><i class="fa-solid" :class="option.icon" aria-hidden="true"></i> {{ option.label }}</span><span class="admin-muted text-xs">{{ option.hint }}</span></span>
                    </label>
                </div>
            </fieldset>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2"><Field id="sku" label="Product code (Kode Produk in Erzap)" :error="form.errors.sku"><template #default="{ describedBy }"><input id="sku" v-model="form.sku" required maxlength="80" :aria-describedby="describedBy" :aria-invalid="!!form.errors.sku"></template></Field><Field id="category_id" label="Category" :error="form.errors.category_id"><template #default="{ describedBy }"><select id="category_id" v-model="form.category_id" required :aria-describedby="describedBy" :aria-invalid="!!form.errors.category_id"><option value="" disabled>Choose a category</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option></select></template></Field></div>
            <p v-if="!categories.length" class="text-sm">Add a category first under Categories.</p>
            <Field id="barcode" label="Barcode (from Erzap)" hint="Must be exactly the barcode of this product and size in Erzap; orders reach Erzap by barcode. Leave empty only for products not sent to Erzap." :error="form.errors.barcode"><template #default="{ describedBy }"><input id="barcode" v-model.trim="form.barcode" maxlength="80" inputmode="numeric" autocomplete="off" class="font-mono" :aria-describedby="describedBy" :aria-invalid="!!form.errors.barcode"></template></Field>
            <Field id="variant" label="Variant (optional)" hint="E.g. Fullsize, Halfsize (20x10), Box 2x2. Each size is its own product (own card, photo, price and Erzap barcode)." :error="form.errors.variant"><template #default="{ describedBy }"><input id="variant" v-model="form.variant" maxlength="40" list="variant-suggestions" placeholder="Leave empty if there is only one kind" :aria-describedby="describedBy" :aria-invalid="!!form.errors.variant"><datalist id="variant-suggestions"><option value="Fullsize" /><option value="Halfsize" /></datalist></template></Field>
            <Field id="description" label="Description" :error="form.errors.description"><template #default="{ describedBy }"><textarea id="description" v-model="form.description" rows="4" maxlength="5000" :aria-describedby="describedBy"></textarea></template></Field>
            <Field v-if="kind !== 'content'" id="price" :label="kind === 'box' ? 'Price per box (Rp)' : kind === 'hampers' ? 'Hampers price without its contents (Rp)' : 'Price (Rp)'" :error="form.errors.price"><template #default="{ describedBy }"><input id="price" v-model="form.price" type="number" min="0" max="999999999" step="1" :required="form.is_active" :aria-describedby="describedBy" :aria-invalid="!!form.errors.price"></template></Field>
            <p v-else class="admin-alert admin-alert-info m-0 text-sm"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Price Rp 0 and inactive: this flavour is not shown as its own product. Tick it in the box (e.g. Mix n Match) under <em>Can be chosen as contents</em>. Its barcode still goes to Erzap.</p>
            <fieldset class="space-y-4 rounded-xl border border-chocolate/15 p-4">
                <legend class="px-2 text-sm font-bold">Contents and sale period</legend>
                <template v-if="form.content_type && form.content_type !== 'flavour'">
                    <div v-if="form.content_type !== 'set'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field id="content_count" label="Items per unit" :hint="form.content_type === 'hampers' ? 'Hampers always hold 1 item.' : 'E.g. 4 for 2x2, 9 for 3x3. The same item may be chosen more than once.'" :error="form.errors.content_count"><template #default="{ describedBy }"><input id="content_count" v-model.number="form.content_count" type="number" min="1" max="50" required :disabled="form.content_type === 'hampers'" :aria-describedby="describedBy"></template></Field>
                        <Field id="content_pricing" label="Price of the contents" :error="form.errors.content_pricing"><template #default="{ describedBy }"><select id="content_pricing" v-model="form.content_pricing" :aria-describedby="describedBy"><option value="added">Added: hampers price + the item's normal price</option><option value="included">Included: Rp 0 (the price is per box)</option></select></template></Field>
                    </div>
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <p v-if="form.content_type === 'set'" class="m-0 text-sm font-bold">Flavours in this box <span class="admin-muted font-normal">({{ form.content_ids.length }} pieces)</span></p>
                            <p v-else class="m-0 text-sm font-bold">{{ flavourPool ? 'Flavours the customer can choose' : 'Products the customer can choose' }} <span class="admin-muted font-normal">({{ form.content_ids.length }} selected)</span></p>
                            <div v-if="form.content_type !== 'set'" class="flex flex-wrap items-center gap-3 text-xs">
                                <label v-if="form.variant && form.content_type === 'hampers'" class="flex items-center gap-1.5"><input v-model="sameSize" type="checkbox">Only size "{{ form.variant }}"</label>
                                <button type="button" class="underline" @click="toggleAll(true)">Select shown</button><button type="button" class="underline" @click="toggleAll(false)">Clear shown</button>
                            </div>
                        </div>
                        <input v-model="contentSearch" type="search" placeholder="Search name, size or code" aria-label="Search products to use as contents">
                        <div class="max-h-72 space-y-1 overflow-y-auto rounded-xl border border-chocolate/15 p-2" role="group" aria-label="Products that can be chosen as contents">
                            <template v-if="form.content_type === 'set'">
                                <div v-for="item in candidates" :key="item.id" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-cream">
                                    <span class="flex-1">{{ item.name }}<span v-if="item.variant" class="admin-muted"> · {{ item.variant }}</span> <span class="admin-muted text-xs">{{ item.sku }}</span></span>
                                    <span class="inline-flex items-center gap-1" role="group" :aria-label="`Pieces of ${item.name}`">
                                        <button type="button" class="admin-action-icon" :aria-label="`Less ${item.name}`" :disabled="!pieces(item.id)" @click="setPieces(item.id, -1)"><i class="fa-solid fa-minus" aria-hidden="true"></i></button>
                                        <span class="w-6 text-center font-bold tabular-nums">{{ pieces(item.id) }}</span>
                                        <button type="button" class="admin-action-icon" :aria-label="`More ${item.name}`" :disabled="form.content_ids.length >= 50" @click="setPieces(item.id, 1)"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>
                                    </span>
                                </div>
                            </template>
                            <label v-for="item in (form.content_type === 'set' ? [] : candidates)" :key="item.id" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-cream">
                                <input v-model="form.content_ids" type="checkbox" :value="item.id">
                                <span class="flex-1">{{ item.name }}<span v-if="item.variant" class="admin-muted"> · {{ item.variant }}</span> <span class="admin-muted text-xs">{{ item.sku }}</span></span>
                                <span class="text-xs tabular-nums" :class="{ 'admin-muted': form.content_pricing === 'included' }">{{ item.price ? `Rp ${item.price.toLocaleString('id-ID')}` : 'no price' }}</span>
                                <span v-if="!item.is_active && form.content_pricing === 'added'" class="text-xs admin-error-text">inactive</span>
                            </label>
                            <p v-if="!candidates.length" class="admin-muted m-0 p-2 text-sm">{{ flavourPool ? 'No box flavours yet. Add each flavour as a product with type "Box flavour only".' : 'No products match.' }}</p>
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
            <fieldset class="grid grid-cols-1 gap-4 rounded-xl border border-chocolate/15 p-4 sm:grid-cols-2">
                <legend class="px-2 text-sm font-bold">Capacity and pickup hours</legend>
                <Field id="daily_quota" label="Orders per day" hint="Pickup and delivery from this outlet. A full date cannot be chosen (100 regular, 30 at malls)." :error="form.errors.daily_quota"><template #default="{ describedBy }"><input id="daily_quota" v-model.number="form.daily_quota" type="number" min="1" max="10000" required :aria-describedby="describedBy" :aria-invalid="!!form.errors.daily_quota"></template></Field>
                <Field id="hourly_quota" label="Pickups per hourly slot" hint="A full slot closes on the order form." :error="form.errors.hourly_quota"><template #default="{ describedBy }"><input id="hourly_quota" v-model.number="form.hourly_quota" type="number" min="1" max="1000" required :aria-describedby="describedBy" :aria-invalid="!!form.errors.hourly_quota"></template></Field>
                <Field id="first_slot" label="First slot (WITA)" hint="After the CK delivery arrives, e.g. 13:00." :error="form.errors.first_slot"><template #default="{ describedBy }"><input id="first_slot" v-model="form.first_slot" type="time" step="60" required :aria-describedby="describedBy" :aria-invalid="!!form.errors.first_slot"></template></Field>
                <Field id="closes_at" label="Outlet closes (WITA)" hint="The last slot starts an hour earlier; pickups are kept until closing." :error="form.errors.closes_at"><template #default="{ describedBy }"><input id="closes_at" v-model="form.closes_at" type="time" step="60" required :aria-describedby="describedBy" :aria-invalid="!!form.errors.closes_at"></template></Field>
            </fieldset>
        </template>
        <fieldset v-if="resource === 'categories'" class="space-y-3 rounded-xl border border-chocolate/15 p-4">
            <legend class="px-2 text-sm font-bold">Extras in the order popup</legend>
            <p class="admin-muted m-0 text-xs">Offered as optional extras (e.g. paper bag, candle, topper, card) when a customer opens any product of this category on /order. They are added to the cart as their own products, at their own price. Only active products with a price are shown to customers.</p>
            <p v-if="form.errors.addon_ids" class="admin-error-text m-0 text-sm" role="alert">{{ form.errors.addon_ids }}</p>
            <input v-model="addonSearch" type="search" placeholder="Search products" aria-label="Search extras">
            <ul class="m-0 max-h-72 list-none space-y-1 overflow-y-auto p-0">
                <li v-for="item in addonList" :key="item.id"><label class="flex items-center gap-3 rounded-lg px-2 py-1.5 text-sm hover:bg-cream/60">
                    <input v-model="form.addon_ids" type="checkbox" :value="item.id">
                    <span class="flex-1">{{ item.name }}{{ item.variant ? ` (${item.variant})` : '' }} <span class="admin-muted text-xs">· {{ item.category }}</span></span>
                    <span class="text-xs tabular-nums" :class="item.is_active && item.price ? '' : 'admin-error-text'">{{ item.price ? `Rp ${item.price.toLocaleString('id-ID')}` : 'no price' }}{{ item.is_active ? '' : ' · inactive' }}</span>
                </label></li>
            </ul>
            <p class="admin-muted m-0 text-xs">{{ form.addon_ids.length }} selected.</p>
        </fieldset>
        <Field v-if="resource === 'categories'" id="order_position" label="Order on the order form" hint="Lower numbers come first in the category chips on /order, e.g. 1 for Brownies. Categories with the same number are sorted by name." :error="form.errors.order_position"><template #default="{ describedBy }"><input id="order_position" v-model.number="form.order_position" type="number" min="0" max="999" required class="max-w-32" :aria-describedby="describedBy"></template></Field>
        <ImageInput id="photo" :label="{ products: 'Product photo', outlets: 'Outlet photo', categories: 'Category photo' }[resource]" :current="record?.image ?? null" :error="form.errors.upload" @select="file => (form.upload = file)" />
        <fieldset class="space-y-3 rounded-xl border border-chocolate/15 p-4">
            <legend class="px-2 text-sm font-bold">Status</legend>
            <label class="flex items-start gap-3 text-sm"><input v-model="form.is_active" type="checkbox" class="mt-0.5" :disabled="resource === 'products' && kind === 'content'"><span><span class="block font-bold">Active</span><span class="admin-muted text-xs">{{ { products: 'The product can be chosen on the PO form.', outlets: 'The outlet is operating. Showing it on the website is set under Website content → Outlet order.', categories: 'Products in this category can be ordered. Showing it in Baked Goods is set under Website content.' }[resource] }}</span></span></label>
            <label v-if="resource === 'outlets'" class="flex items-start gap-3 text-sm"><input v-model="form.delivers" type="checkbox" class="mt-0.5"><span><span class="block font-bold">Ships delivery</span><span class="admin-muted text-xs">Customers can choose this outlet for delivery (Gojek/Grab). "Nearest outlet" orders start at the first delivery outlet in website order until staff choose.</span></span></label>
            <label v-if="resource === 'outlets'" class="flex items-start gap-3 text-sm"><input v-model="form.accepts_preorder" type="checkbox" class="mt-0.5"><span><span class="block font-bold">Takes pre-orders</span><span class="admin-muted text-xs">The outlet appears as a choice on the pre-order form (only while active).</span></span></label>
        </fieldset>
        <p v-if="resource === 'products'" class="text-sm text-chocolate/60">A price is required before activating a product. Staff still check availability and production capacity before payment.</p>
        <div class="flex flex-wrap gap-3"><button class="admin-primary" :disabled="form.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ form.processing ? 'Saving…' : 'Save' }}</button><Link :href="listUrl" class="admin-secondary"><i class="fa-solid fa-xmark" aria-hidden="true"></i>Cancel</Link></div>
    </form>
</template>

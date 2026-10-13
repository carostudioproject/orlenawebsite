<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import ShopLayout from '../../Layouts/ShopLayout.vue';
import ProductPicker from '../../Components/Shop/ProductPicker.vue';
import CartPanel, { type PanelLine } from '../../Components/Shop/CartPanel.vue';
import { rupiah } from '../../Support/money';
import { addLine, contentsLabel, toRequest, unitPrice, type CartLine } from '../../Support/cart';
import type { OrderCategory, OrderItem, OrderProduct } from '../../Types/ordering';
defineOptions({ layout: ShopLayout });
interface OrderSnapshot { order_code: string; fulfillment_method: 'pickup' | 'delivery'; outlet_name_snapshot: string; requested_date: string; requested_time: string | null; subtotal: number; delivery_fee: number | null; total: number; items: OrderItem[] }
const props = defineProps<{ order: OrderSnapshot; categories: OrderCategory[]; products: OrderProduct[]; blocker: string | null; addKey: string }>();

// Only the additions live in this cart; prices are the order outlet's current prices.
const form = useForm({ add_key: props.addKey, items: [] as CartLine[] });
watch(() => props.addKey, key => { form.add_key = key; });
const productById = computed(() => new Map(props.products.map(product => [product.id, product])));
function add(productId: number, quantity: number, contents: number[]) {
    addLine(form.items, productId, quantity, contents);
    form.clearErrors();
}
const additions = computed(() => form.items.map((item, index) => {
    const product = productById.value.get(item.product_id);
    return { ...item, index, product, unit: unitPrice(product, item.contents), contentsText: contentsLabel(product, item.contents) };
}));
const addedSubtotal = computed(() => additions.value.reduce((sum, row) => sum + row.unit * row.quantity, 0));
// Contents are shown under their hampers/box line.
const currentLines = computed(() => props.order.items.filter(item => !item.parent_item_id).map(item => {
    const contents = props.order.items.filter(child => child.parent_item_id === item.id);
    return { ...item, total: item.subtotal + contents.reduce((sum, child) => sum + child.subtotal, 0), contents };
}));
const newTotal = computed(() => props.order.total + addedSubtotal.value);
const errors = computed(() => form.errors as Record<string, string>);
const errorSummary = ref<HTMLElement | null>(null);
const schedule = computed(() => `${props.order.requested_date}${props.order.requested_time ? `, ${props.order.requested_time.slice(0, 5)} WITA` : ''}`);
function submit() {
    if (!form.items.length || form.processing) return;
    form.transform(data => ({ ...data, items: toRequest(data.items, productById.value) }))
        .post(`/orders/${props.order.order_code}/tambah`, { preserveScroll: true, onError: async () => { await nextTick(); errorSummary.value?.focus(); } });
}
// The cart bar and drawer (CartPanel) edit the additions.
const cartPanel = ref<InstanceType<typeof CartPanel> | null>(null);
const itemError = (index: number) => errors.value[`items.${index}.quantity`] ?? errors.value[`items.${index}.contents`] ?? errors.value[`items.${index}.product_id`];
const panelLines = computed<PanelLine[]>(() => additions.value.map(row => ({
    key: `${row.product_id}:${row.contents.join(',')}`, index: row.index, name: row.product?.name ?? 'Produk tidak tersedia', detail: row.product?.variant ?? '',
    contentsText: row.contentsText, unit: row.unit, quantity: row.quantity, error: itemError(row.index),
})));
function scrollToSummary() { document.getElementById('summary')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
</script>
<template>
    <Link :href="`/orders/${order.order_code}`" class="admin-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Kembali ke ringkasan pesanan</Link>
    <p class="admin-eyebrow mt-5">Tambah pesanan</p>
    <h1 class="break-all text-4xl">{{ order.order_code }}</h1>
    <p class="mt-3 text-sm text-chocolate/70">{{ order.fulfillment_method === 'delivery' ? `Delivery (Gojek/Grab) dari ${order.outlet_name_snapshot}` : `Pickup di ${order.outlet_name_snapshot}` }} · {{ schedule }}</p>

    <div v-if="blocker" role="status" class="admin-alert admin-alert-info mt-6 max-w-2xl"><p class="m-0 font-bold">Pesanan ini tidak dapat ditambah lagi</p><p class="m-0 mt-1">{{ blocker }}</p></div>

    <form v-else class="mt-8 space-y-6 pb-28" @submit.prevent="submit">
        <div v-if="form.hasErrors" ref="errorSummary" tabindex="-1" role="alert" class="admin-alert admin-alert-error"><p class="font-bold">Tambahan belum tersimpan:</p><ul class="list-disc pl-5"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>
        <section class="admin-card space-y-5" aria-labelledby="pick-title">
            <h2 id="pick-title" class="text-xl"><span class="order-step">1</span>Pilih produk tambahan</h2>
            <p class="admin-muted -mt-3 text-sm">Harga mengikuti {{ order.outlet_name_snapshot }}. Produk yang sama akan digabung dengan jumlah di pesanan Anda.</p>
            <ProductPicker :categories="categories" :products="products" :disabled="form.processing" @add="add" />
        </section>

        <section id="summary" class="admin-card space-y-5" aria-labelledby="summary-title">
            <div class="flex items-center justify-between gap-3">
                <h2 id="summary-title" class="text-xl"><span class="order-step">2</span>Ringkasan &amp; simpan</h2>
                <button v-if="additions.length" type="button" class="admin-action" @click="cartPanel?.open()"><i class="fa-solid fa-pen" aria-hidden="true"></i>Ubah tambahan</button>
            </div>
            <div>
                <p class="admin-eyebrow mb-2">Pesanan saat ini</p>
                <ul class="m-0 list-none space-y-1 p-0 text-sm"><li v-for="item in currentLines" :key="item.id"><div class="flex justify-between gap-3"><span>{{ item.quantity }}× {{ item.product_name_snapshot }}<span v-if="item.variant_snapshot" class="admin-muted"> ({{ item.variant_snapshot }})</span></span><span class="shrink-0 tabular-nums">{{ rupiah(item.total) }}</span></div><p v-if="item.contents.length" class="admin-muted m-0 text-xs">Isi: {{ item.contents.map(child => `${child.quantity}× ${child.product_name_snapshot}`).join(', ') }}</p></li></ul>
            </div>
            <div class="border-t border-chocolate/10 pt-4">
                <p class="admin-eyebrow mb-2">Tambahan</p>
                <p v-if="!additions.length" class="admin-muted text-sm">Belum ada tambahan. Pilih produk di langkah 1.</p>
                <ul v-else class="m-0 list-none divide-y divide-chocolate/10 p-0">
                    <li v-for="row in additions" :key="`${row.product_id}:${row.contents.join(',')}`" class="flex justify-between gap-3 py-2.5 text-sm">
                        <div class="min-w-0">
                            <p class="m-0 font-bold">{{ row.quantity }} × {{ row.product?.name }}<span v-if="row.product?.variant" class="admin-muted font-normal"> ({{ row.product.variant }})</span></p>
                            <p v-if="row.contentsText" class="m-0 text-xs text-chocolate/75"><i class="fa-solid fa-gift" aria-hidden="true"></i> Isi: {{ row.contentsText }}</p>
                            <p v-if="itemError(row.index)" class="admin-error-text m-0 text-xs" role="alert">{{ itemError(row.index) }}</p>
                        </div>
                        <span class="shrink-0 font-bold tabular-nums">{{ rupiah(row.unit * row.quantity) }}</span>
                    </li>
                </ul>
            </div>
            <dl class="space-y-2 border-t border-chocolate/10 pt-4 text-sm">
                <div class="flex justify-between"><dt>Total saat ini</dt><dd class="m-0 tabular-nums">{{ rupiah(order.total) }}</dd></div>
                <div class="flex justify-between"><dt>Tambahan</dt><dd class="m-0 tabular-nums">+ {{ rupiah(addedSubtotal) }}</dd></div>
                <div class="flex justify-between text-base font-bold"><dt>Total awal baru</dt><dd class="m-0 tabular-nums">{{ rupiah(newTotal) }}</dd></div>
            </dl>
            <p v-if="order.delivery_fee === null" class="admin-muted text-xs">Belum termasuk ongkir Gojek/Grab yang dikonfirmasi admin.</p>
            <button class="admin-primary w-full" :disabled="!additions.length || form.processing"><i class="fa-solid fa-cart-plus" aria-hidden="true"></i>{{ form.processing ? 'Menyimpan…' : 'Simpan tambahan' }}</button>
            <p class="admin-muted text-xs">Setelah disimpan, kirim update ke Orlena melalui WhatsApp. Ketersediaan tambahan tetap dikonfirmasi admin.</p>
        </section>

        <CartPanel ref="cartPanel" title="tambahan" :lines="panelLines" :subtotal="addedSubtotal" :disabled="form.processing" @quantity="(index, quantity) => { form.items[index].quantity = quantity; }" @remove="index => { form.items.splice(index, 1); form.clearErrors(); }">
            <template #action="{ close }"><button type="button" class="admin-primary flex-1" @click="close(); scrollToSummary()">Lanjut ke ringkasan</button></template>
        </CartPanel>
    </form>
</template>

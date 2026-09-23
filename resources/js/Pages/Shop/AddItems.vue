<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import ShopLayout from '../../Layouts/ShopLayout.vue';
import ProductPicker from '../../Components/Shop/ProductPicker.vue';
import { rupiah } from '../../Support/money';
import type { OrderCategory, OrderItem, OrderProduct } from '../../Types/ordering';
defineOptions({ layout: ShopLayout });
interface OrderSnapshot { order_code: string; fulfillment_method: 'pickup' | 'delivery'; outlet_name_snapshot: string; requested_date: string; requested_time: string | null; subtotal: number; delivery_fee: number | null; total: number; items: OrderItem[] }
const props = defineProps<{ order: OrderSnapshot; categories: OrderCategory[]; products: OrderProduct[]; blocker: string | null; addKey: string }>();

// Only the additions live in this cart; prices are the order outlet's current prices.
const form = useForm({ add_key: props.addKey, items: [] as { product_id: number; quantity: number }[] });
watch(() => props.addKey, key => { form.add_key = key; });
const productById = computed(() => new Map(props.products.map(product => [product.id, product])));
function add(productId: number, quantity: number) {
    const existing = form.items.find(item => item.product_id === productId);
    if (existing) existing.quantity = Math.min(99, existing.quantity + quantity);
    else if (form.items.length < 50) form.items.push({ product_id: productId, quantity });
    form.clearErrors();
}
const clamp = (value: number) => Math.min(99, Math.max(1, Math.round(value) || 1));
const additions = computed(() => form.items.map((item, index) => ({ ...item, index, product: productById.value.get(item.product_id) })));
const addedSubtotal = computed(() => additions.value.reduce((sum, row) => sum + (row.product?.price ?? 0) * row.quantity, 0));
const newTotal = computed(() => props.order.total + addedSubtotal.value);
const errors = computed(() => form.errors as Record<string, string>);
const errorSummary = ref<HTMLElement | null>(null);
const schedule = computed(() => `${props.order.requested_date}${props.order.requested_time ? `, ${props.order.requested_time.slice(0, 5)} WITA` : ''}`);
function submit() {
    if (!form.items.length || form.processing) return;
    form.transform(data => ({ ...data, items: data.items.map(item => ({ ...item, quoted_price: productById.value.get(item.product_id)?.price ?? 0 })) }))
        .post(`/orders/${props.order.order_code}/tambah`, { preserveScroll: true, onError: async () => { await nextTick(); errorSummary.value?.focus(); } });
}
</script>
<template>
    <Link :href="`/orders/${order.order_code}`" class="admin-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Kembali ke ringkasan pesanan</Link>
    <p class="admin-eyebrow mt-5">Tambah pesanan</p>
    <h1 class="break-all text-4xl">{{ order.order_code }}</h1>
    <p class="mt-3 text-sm text-chocolate/70">{{ order.fulfillment_method === 'delivery' ? `Delivery (Gojek/Grab) dari ${order.outlet_name_snapshot}` : `Pickup di ${order.outlet_name_snapshot}` }} · {{ schedule }}</p>

    <div v-if="blocker" role="status" class="admin-alert admin-alert-info mt-6 max-w-2xl"><p class="m-0 font-bold">Pesanan ini tidak dapat ditambah lagi</p><p class="m-0 mt-1">{{ blocker }}</p></div>

    <form v-else class="mt-8 grid items-start gap-7 lg:grid-cols-[minmax(0,1fr)_360px]" @submit.prevent="submit">
        <div class="min-w-0 space-y-6">
            <div v-if="form.hasErrors" ref="errorSummary" tabindex="-1" role="alert" class="admin-alert admin-alert-error"><p class="font-bold">Tambahan belum tersimpan:</p><ul class="list-disc pl-5"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>
            <section class="admin-card space-y-5" aria-labelledby="pick-title">
                <h2 id="pick-title" class="text-xl">Pilih produk tambahan</h2>
                <p class="admin-muted -mt-3 text-sm">Harga mengikuti {{ order.outlet_name_snapshot }}. Produk yang sama akan digabung dengan jumlah di pesanan Anda.</p>
                <ProductPicker :categories="categories" :products="products" :disabled="form.processing" @add="add" />
            </section>
        </div>

        <aside class="admin-card space-y-5 lg:sticky lg:top-24" aria-labelledby="summary-title">
            <h2 id="summary-title" class="text-xl">Ringkasan</h2>
            <div>
                <p class="admin-eyebrow mb-2">Pesanan saat ini</p>
                <ul class="space-y-1 text-sm"><li v-for="item in order.items" :key="item.id" class="flex justify-between gap-3"><span>{{ item.quantity }}× {{ item.product_name_snapshot }}<span v-if="item.variant_snapshot" class="admin-muted"> ({{ item.variant_snapshot }})</span></span><span class="shrink-0 tabular-nums">{{ rupiah(item.subtotal) }}</span></li></ul>
            </div>
            <div class="border-t border-chocolate/10 pt-4">
                <p class="admin-eyebrow mb-2">Tambahan</p>
                <p v-if="!additions.length" class="admin-muted text-sm">Belum ada tambahan. Pilih produk lalu tekan Tambah.</p>
                <ul v-else class="divide-y divide-chocolate/10">
                    <li v-for="row in additions" :key="row.product_id" class="space-y-2 py-2.5 text-sm">
                        <div class="flex justify-between gap-3"><span class="font-bold">{{ row.product?.name }}<span v-if="row.product?.variant" class="admin-muted font-normal"> ({{ row.product.variant }})</span></span><span class="shrink-0 font-bold tabular-nums">{{ rupiah((row.product?.price ?? 0) * row.quantity) }}</span></div>
                        <div class="flex items-center justify-between gap-2">
                            <div class="order-stepper is-small" role="group" :aria-label="`Jumlah ${row.product?.name}`"><button type="button" aria-label="Kurangi" :disabled="row.quantity <= 1" @click="form.items[row.index].quantity--"><i class="fa-solid fa-minus" aria-hidden="true"></i></button><input v-model.number="form.items[row.index].quantity" class="order-qty" type="number" min="1" max="99" inputmode="numeric" :aria-label="`Jumlah ${row.product?.name}`" @change="form.items[row.index].quantity = clamp(form.items[row.index].quantity)"><button type="button" aria-label="Tambah" :disabled="row.quantity >= 99" @click="form.items[row.index].quantity++"><i class="fa-solid fa-plus" aria-hidden="true"></i></button></div>
                            <button type="button" class="admin-action-icon admin-danger" :aria-label="`Hapus ${row.product?.name} dari tambahan`" title="Hapus" @click="form.items.splice(row.index, 1)"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
                        </div>
                        <p v-if="errors[`items.${row.index}.quantity`] || errors[`items.${row.index}.product_id`]" class="admin-error-text m-0 text-xs" role="alert">{{ errors[`items.${row.index}.quantity`] ?? errors[`items.${row.index}.product_id`] }}</p>
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
        </aside>
    </form>
</template>

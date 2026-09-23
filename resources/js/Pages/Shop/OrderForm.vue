<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import ShopLayout from '../../Layouts/ShopLayout.vue';
import Field from '../../Components/Admin/Field.vue';
import ProductPicker from '../../Components/Shop/ProductPicker.vue';
import type { OrderCategory, OrderProduct } from '../../Types/ordering';
import { rupiah } from '../../Support/money';
defineOptions({ layout: ShopLayout });
interface OutletOption { id: number; name: string; address: string }
const props = defineProps<{
    categories: OrderCategory[]; products: OrderProduct[]; outlets: OutletOption[]; deliveryOutlet: OutletOption | null;
    selectedOutlet: number | null; earliestDate: string; checkoutKey: string;
    closedDates: { date: string; reason: string | null }[]; closedWeekdays: number[];
}>();
const cutoffLabel = computed(() => (usePage().props.poCutoff as string) ?? 'H-1 pukul 18.00 WITA');
// Closed dates are a hint while choosing; the server checks the date again on submit.
const weekdayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const closedDateHint = computed(() => {
    if (!form.requested_date) return null;
    const weekday = new Date(`${form.requested_date}T00:00:00Z`).getUTCDay();
    if (props.closedWeekdays.includes(weekday)) return `Orlena tidak menerima PO untuk hari ${weekdayNames[weekday]}. Pilih tanggal lain.`;
    const closed = props.closedDates.find(item => item.date === form.requested_date);
    return closed ? `Tanggal ini tutup untuk PO${closed.reason ? ` (${closed.reason})` : ''}. Pilih tanggal lain.` : null;
});

const form = useForm({
    checkout_key: props.checkoutKey, items: [] as { product_id: number; quantity: number }[],
    name: '', whatsapp: '', email: '', fulfillment_method: 'pickup' as 'pickup' | 'delivery', outlet_id: '' as number | string,
    requested_date: '', requested_time: '', delivery_address: '', customer_note: '',
});
watch(() => props.checkoutKey, key => { form.checkout_key = key; });

// Step 1 lives in <ProductPicker>; this page owns the cart.
const productById = computed(() => new Map(props.products.map(product => [product.id, product])));
const categoryName = (id: number) => props.categories.find(category => category.id === id)?.name ?? '';
function addToCart(productId: number, quantity: number) {
    const existing = form.items.find(item => item.product_id === productId);
    if (existing) existing.quantity = Math.min(99, existing.quantity + quantity);
    else if (form.items.length < 50) form.items.push({ product_id: productId, quantity });
    form.clearErrors();
}
const clamp = (value: number) => Math.min(99, Math.max(1, Math.round(value) || 1));
const cart = computed(() => form.items.map((item, index) => ({ ...item, index, product: productById.value.get(item.product_id) })));
const cartCount = computed(() => form.items.reduce((sum, item) => sum + item.quantity, 0));
const subtotal = computed(() => cart.value.reduce((sum, row) => sum + (row.product?.price ?? 0) * row.quantity, 0));

// Prices follow the outlet that fulfils the order: the chosen pickup outlet or the delivery outlet.
const effectiveOutlet = computed(() => (form.fulfillment_method === 'delivery' ? props.deliveryOutlet?.id ?? null : Number(form.outlet_id) || null));
const quoting = ref(false);
const priceRefreshFailed = ref(false);
function refreshQuote() {
    if (!effectiveOutlet.value) return;
    quoting.value = true;
    priceRefreshFailed.value = true;
    router.get('/order', { outlet_id: effectiveOutlet.value }, {
        only: ['products', 'selectedOutlet'], preserveState: true, preserveScroll: true, replace: true,
        onSuccess: () => { priceRefreshFailed.value = false; }, onFinish: () => { quoting.value = false; },
    });
}
watch(effectiveOutlet, outlet => { if (outlet && outlet !== props.selectedOutlet) refreshQuote(); });
const pricesReady = computed(() => !quoting.value && !priceRefreshFailed.value && !!effectiveOutlet.value && props.selectedOutlet === effectiveOutlet.value);
const pickupOutlet = computed(() => props.outlets.find(outlet => outlet.id === Number(form.outlet_id)));

const errorSummary = ref<HTMLElement | null>(null);
const errors = computed(() => form.errors as Record<string, string>);
const itemError = (index: number) => errors.value[`items.${index}.product_id`] ?? errors.value[`items.${index}.quantity`];
const canSubmit = computed(() => !form.processing && pricesReady.value && form.items.length > 0 && cart.value.every(row => row.product) && !closedDateHint.value);
function submit() {
    if (!canSubmit.value) return;
    form.transform(data => ({
        ...data, outlet_id: effectiveOutlet.value,
        items: data.items.map(item => ({ ...item, quoted_price: productById.value.get(item.product_id)?.price ?? 0 })),
    })).post('/order', { preserveScroll: true, onError: async () => { await nextTick(); errorSummary.value?.focus(); } });
}
function scrollToCart() { document.getElementById('cart')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
</script>
<template>
    <h1 class="text-4xl">Form order Orlena</h1>
    <p class="mt-4 max-w-2xl leading-relaxed text-chocolate/65">Pilih kategori, produk, dan jumlah, lalu lengkapi data pemesanan. Pesanan akan diperiksa tim Orlena terlebih dahulu; pembayaran dilakukan setelah produk, jadwal, dan total dikonfirmasi.</p>
    <p class="mt-3 text-sm"><a href="/order/tambah" class="font-bold underline"><i class="fa-solid fa-cart-plus" aria-hidden="true"></i> Sudah punya Order Code? Tambah ke pesanan yang belum dibayar</a></p>
    <div v-if="!products.length || (!outlets.length && !deliveryOutlet)" role="status" class="my-8 rounded-2xl bg-cream p-6"><h2 class="text-xl">Pemesanan belum tersedia</h2><p class="mt-2 text-sm">Produk dan outlet sedang disiapkan. Form dapat digunakan setelah pilihan tersedia.</p></div>

    <form class="mt-8 grid items-start gap-7 pb-24 lg:grid-cols-[minmax(0,1fr)_360px] lg:pb-0" @submit.prevent="submit">
        <div class="min-w-0 space-y-6">
            <div v-if="form.hasErrors" ref="errorSummary" tabindex="-1" role="alert" class="admin-alert admin-alert-error"><p class="font-bold">Pesanan belum terkirim. Periksa kembali:</p><ul class="list-disc pl-5"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul><button type="button" class="mt-2 underline" :disabled="quoting || form.processing" @click="refreshQuote">Muat ulang harga</button></div>

            <section class="admin-card space-y-5" aria-labelledby="step-products">
                <h2 id="step-products" class="text-xl"><span class="order-step">1</span>Pilih produk</h2>
                <ProductPicker :categories="categories" :products="products" :disabled="form.processing" @add="addToCart" />
            </section>

            <section class="admin-card space-y-5" aria-labelledby="step-customer">
                <h2 id="step-customer" class="text-xl"><span class="order-step">2</span>Data pemesan</h2>
                <Field id="name" label="Nama lengkap" :error="form.errors.name"><template #default="{ describedBy }"><input id="name" v-model="form.name" required autocomplete="name" maxlength="120" :aria-describedby="describedBy" :aria-invalid="!!form.errors.name"></template></Field>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <Field id="whatsapp" label="Nomor WhatsApp" :error="form.errors.whatsapp"><template #default="{ describedBy }"><input id="whatsapp" v-model="form.whatsapp" type="tel" required autocomplete="tel" placeholder="08… atau +62…" maxlength="30" :aria-describedby="describedBy" :aria-invalid="!!form.errors.whatsapp"></template></Field>
                    <Field id="email" label="Email (opsional)" :error="form.errors.email"><template #default="{ describedBy }"><input id="email" v-model="form.email" type="email" autocomplete="email" maxlength="254" :aria-describedby="describedBy" :aria-invalid="!!form.errors.email"></template></Field>
                </div>
            </section>

            <section class="admin-card space-y-5" aria-labelledby="step-fulfillment">
                <h2 id="step-fulfillment" class="text-xl"><span class="order-step">3</span>Penerimaan &amp; jadwal</h2>
                <fieldset class="grid grid-cols-1 gap-3 sm:grid-cols-2"><legend class="mb-2 text-sm font-bold">Metode penerimaan</legend>
                    <label class="order-method"><input v-model="form.fulfillment_method" type="radio" value="pickup" class="sr-only"><span><i class="fa-solid fa-store" aria-hidden="true"></i><strong>Pickup di outlet</strong><small>Ambil sendiri di outlet pilihan.</small></span></label>
                    <label class="order-method" :class="{ 'is-disabled': !deliveryOutlet }"><input v-model="form.fulfillment_method" type="radio" value="delivery" class="sr-only" :disabled="!deliveryOutlet"><span><i class="fa-solid fa-motorcycle" aria-hidden="true"></i><strong>Delivery (Gojek/Grab)</strong><small>{{ deliveryOutlet ? `Dikirim dari ${deliveryOutlet.name}.` : 'Sedang tidak tersedia.' }}</small></span></label>
                </fieldset>
                <p v-if="form.errors.fulfillment_method" class="admin-error-text text-sm" role="alert">{{ form.errors.fulfillment_method }}</p>
                <template v-if="form.fulfillment_method === 'pickup'">
                    <Field id="outlet_id" label="Outlet pickup" :error="form.errors.outlet_id"><template #default="{ describedBy }"><select id="outlet_id" v-model="form.outlet_id" required :disabled="form.processing" :aria-describedby="describedBy" :aria-invalid="!!form.errors.outlet_id"><option value="" disabled>Pilih outlet</option><option v-for="outlet in outlets" :key="outlet.id" :value="outlet.id">{{ outlet.name }}</option></select></template></Field>
                    <p v-if="pickupOutlet" class="admin-muted -mt-2 text-xs"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ pickupOutlet.address }}</p>
                </template>
                <template v-else>
                    <p class="admin-alert admin-alert-info">Pesanan dikirim dari <strong>{{ deliveryOutlet?.name }}</strong> menggunakan Gojek/Grab. Ongkir mengikuti tarif aplikasi dan dikonfirmasi admin sebelum pembayaran.</p>
                    <Field id="delivery_address" label="Alamat pengiriman lengkap" :error="form.errors.delivery_address"><template #default="{ describedBy }"><textarea id="delivery_address" v-model="form.delivery_address" required rows="3" maxlength="2000" autocomplete="street-address" placeholder="Nama jalan, nomor rumah, patokan" :aria-describedby="describedBy" :aria-invalid="!!form.errors.delivery_address"></textarea></template></Field>
                </template>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <Field id="requested_date" label="Tanggal PO" :hint="`Paling awal ${earliestDate}. Batas pemesanan ${cutoffLabel}.`" :error="form.errors.requested_date ?? closedDateHint ?? undefined"><template #default="{ describedBy }"><input id="requested_date" v-model="form.requested_date" type="date" required :min="earliestDate" :aria-describedby="describedBy" :aria-invalid="!!form.errors.requested_date"></template></Field>
                    <Field id="requested_time" :label="form.fulfillment_method === 'delivery' ? 'Jam pengiriman (WITA)' : 'Jam pickup (WITA)'" hint="Jam akan dikonfirmasi admin sesuai kapasitas." :error="form.errors.requested_time"><template #default="{ describedBy }"><input id="requested_time" v-model="form.requested_time" type="time" required :aria-describedby="describedBy" :aria-invalid="!!form.errors.requested_time"></template></Field>
                </div>
                <Field id="customer_note" label="Catatan (opsional)" :error="form.errors.customer_note"><template #default="{ describedBy }"><textarea id="customer_note" v-model="form.customer_note" rows="3" maxlength="2000" placeholder="Misalnya tulisan ucapan atau permintaan khusus" :aria-describedby="describedBy"></textarea></template></Field>
            </section>
        </div>

        <aside id="cart" class="admin-card space-y-5 lg:sticky lg:top-24" aria-labelledby="cart-title">
            <h2 id="cart-title" class="flex items-center justify-between text-xl">Keranjang <span class="rounded-full bg-almond px-3 py-0.5 text-sm">{{ cartCount }}</span></h2>
            <p v-if="!cart.length" class="admin-muted py-4 text-center text-sm"><i class="fa-solid fa-basket-shopping mb-2 block text-2xl text-chocolate/25" aria-hidden="true"></i>Keranjang masih kosong. Pilih produk lalu tekan Tambah.</p>
            <ul v-else class="divide-y divide-chocolate/10">
                <li v-for="row in cart" :key="row.product_id" class="space-y-2 py-3 text-sm">
                    <div class="flex justify-between gap-3"><div class="min-w-0"><p class="m-0 font-bold">{{ row.product?.name ?? 'Produk tidak tersedia' }}</p><p class="m-0 text-xs text-matcha">{{ row.product ? categoryName(row.product.category_id) : '' }}{{ row.product?.variant ? ` · ${row.product.variant}` : '' }}</p></div><span class="shrink-0 font-bold tabular-nums">{{ rupiah((row.product?.price ?? 0) * row.quantity) }}</span></div>
                    <div class="flex items-center justify-between gap-2">
                        <div class="order-stepper is-small" role="group" :aria-label="`Jumlah ${row.product?.name}`"><button type="button" aria-label="Kurangi" :disabled="row.quantity <= 1 || form.processing" @click="form.items[row.index].quantity--"><i class="fa-solid fa-minus" aria-hidden="true"></i></button><input v-model.number="form.items[row.index].quantity" class="order-qty" type="number" min="1" max="99" inputmode="numeric" :aria-label="`Jumlah ${row.product?.name}`" @change="form.items[row.index].quantity = clamp(form.items[row.index].quantity)"><button type="button" aria-label="Tambah" :disabled="row.quantity >= 99 || form.processing" @click="form.items[row.index].quantity++"><i class="fa-solid fa-plus" aria-hidden="true"></i></button></div>
                        <button type="button" class="admin-action-icon admin-danger" :aria-label="`Hapus ${row.product?.name} dari keranjang`" title="Hapus" :disabled="form.processing" @click="form.items.splice(row.index, 1); form.clearErrors()"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
                    </div>
                    <p v-if="itemError(row.index)" class="admin-error-text m-0 text-xs" role="alert">{{ itemError(row.index) }}</p>
                </li>
            </ul>
            <dl class="space-y-2 border-t border-chocolate/10 pt-4 text-sm">
                <div class="flex justify-between"><dt>Subtotal</dt><dd class="m-0 tabular-nums">{{ rupiah(subtotal) }}</dd></div>
                <div class="flex justify-between"><dt>Ongkir</dt><dd class="m-0">{{ form.fulfillment_method === 'delivery' ? 'Tarif Gojek/Grab' : 'Tidak ada' }}</dd></div>
                <div class="flex justify-between text-base font-bold"><dt>Total awal</dt><dd class="m-0 tabular-nums">{{ rupiah(subtotal) }}</dd></div>
            </dl>
            <p v-if="quoting" role="status" class="admin-muted text-xs"><i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i> Memperbarui harga outlet…</p>
            <p v-else-if="priceRefreshFailed" role="alert" class="admin-error-text text-xs">Harga outlet belum berhasil dimuat. <button type="button" class="underline" @click="refreshQuote">Coba lagi</button></p>
            <p v-else-if="!effectiveOutlet" class="admin-muted text-xs">Harga final mengikuti outlet. Pilih metode penerimaan dan outlet di langkah 3.</p>
            <button class="admin-primary w-full" :disabled="!canSubmit">{{ form.processing ? 'Menyimpan pesanan…' : 'Kirim permintaan PO' }}</button>
            <p class="admin-muted text-xs leading-relaxed">{{ form.fulfillment_method === 'delivery' ? 'Total awal belum termasuk ongkir. ' : '' }}Ini adalah permintaan PO; ketersediaan produk dan jadwal dikonfirmasi admin. Setelah tersimpan, Anda mendapat Order Code untuk dilanjutkan via WhatsApp.</p>
        </aside>

        <!-- Mobile: keep the cart total in reach while browsing products. -->
        <div v-if="cart.length" class="fixed inset-x-0 bottom-0 z-30 border-t border-chocolate/10 bg-white/95 p-3 backdrop-blur lg:hidden">
            <button type="button" class="admin-primary w-full justify-between" @click="scrollToCart"><span><i class="fa-solid fa-basket-shopping" aria-hidden="true"></i> {{ cartCount }} item</span><span>{{ rupiah(subtotal) }} · Lihat keranjang</span></button>
        </div>
    </form>
</template>

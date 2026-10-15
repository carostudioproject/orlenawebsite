<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import ShopLayout from '../../Layouts/ShopLayout.vue';
import Field from '../../Components/Field.vue';
import ProductPicker from '../../Components/Shop/ProductPicker.vue';
import CartPanel, { type PanelLine } from '../../Components/Shop/CartPanel.vue';
import PoCalendar, { type CalendarDay } from '../../Components/Shop/PoCalendar.vue';
import type { OrderCategory, OrderProduct } from '../../Types/ordering';
import { rupiah } from '../../Support/money';
import { addLine, contentsLabel, toRequest, unitPrice, type CartLine } from '../../Support/cart';
defineOptions({ layout: ShopLayout });
interface ShopOutlet { id: number; name: string; address: string; delivers: boolean; closes_at: string }
const props = defineProps<{
    categories: OrderCategory[]; products: OrderProduct[]; outlets: ShopOutlet[]; nearestOutlet: number | null;
    selectedOutlet: number | null; paymentClose: string; deliveryTolerance: number; deliveryMinOrder: number | null; deliveryRadius: number | null; checkoutKey: string;
}>();
const cutoffLabel = computed(() => (usePage().props.poCutoff as string) ?? 'H-1 pukul 19.00 WITA');
const deliveryOutlets = computed(() => props.outlets.filter(outlet => outlet.delivers));

const form = useForm({
    checkout_key: props.checkoutKey, items: [] as CartLine[],
    name: '', whatsapp: '', email: '', fulfillment_method: 'pickup' as 'pickup' | 'delivery', outlet_id: '' as number | '' | 'nearest',
    requested_date: '', requested_time: '', delivery_address: '', recipient_whatsapp: '', location_url: '', customer_note: '', card_message: '',
});
watch(() => props.checkoutKey, key => { form.checkout_key = key; });

// Step 1 lives in <ProductPicker>; this page owns the cart.
const productById = computed(() => new Map(props.products.map(product => [product.id, product])));
// The optional greeting card appears once a hampers product is in the cart.
const hasHamper = computed(() => form.items.some(item => productById.value.get(item.product_id)?.is_hamper));
const hasCard = computed(() => form.items.some(item => productById.value.get(item.product_id)?.is_card));
const categoryName = (id: number) => props.categories.find(category => category.id === id)?.name ?? '';
function addToCart(productId: number, quantity: number, contents: number[]) {
    addLine(form.items, productId, quantity, contents);
    form.clearErrors();
}
const cart = computed(() => form.items.map((item, index) => {
    const product = productById.value.get(item.product_id);
    return { ...item, index, product, unit: unitPrice(product, item.contents), contentsText: contentsLabel(product, item.contents) };
}));
const subtotal = computed(() => cart.value.reduce((sum, row) => sum + row.unit * row.quantity, 0));

// Prices, quota and slots follow the outlet that fulfils the order; "nearest" delivery uses the first delivery outlet until staff choose.
const effectiveOutlet = computed(() => (form.fulfillment_method === 'delivery' && form.outlet_id === 'nearest' ? props.nearestOutlet : Number(form.outlet_id) || null));
// An outlet that does not ship delivery is cleared when switching to delivery (and "nearest" when switching to pickup).
watch(() => form.fulfillment_method, method => {
    if (method === 'pickup' && form.outlet_id === 'nearest') form.outlet_id = '';
    if (method === 'delivery' && form.outlet_id !== 'nearest' && !deliveryOutlets.value.some(outlet => outlet.id === Number(form.outlet_id))) form.outlet_id = '';
});

// Calendar: open, closed, cut-off and full dates for the outlet, with hourly slots (pickup: places left).
const calendar = ref<CalendarDay[]>([]);
const calendarState = ref<'idle' | 'loading' | 'error'>('idle');
let calendarRequest = 0;
async function loadCalendar() {
    const outlet = effectiveOutlet.value;
    if (!outlet) { calendar.value = []; return; }
    const request = ++calendarRequest;
    calendarState.value = 'loading';
    try {
        const response = await fetch(`/order/jadwal?outlet_id=${outlet}&method=${form.fulfillment_method}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(String(response.status));
        const data = await response.json();
        if (request !== calendarRequest) return;
        calendar.value = data.days;
        calendarState.value = 'idle';
        // A date or time that is no longer available is cleared, never silently kept.
        const day = calendar.value.find(item => item.date === form.requested_date);
        if (day?.status !== 'open') { form.requested_date = ''; form.requested_time = ''; }
        else if (!day.slots.some(slot => slot.time === form.requested_time && slot.left !== 0)) form.requested_time = '';
    } catch {
        if (request === calendarRequest) calendarState.value = 'error';
    }
}
watch([effectiveOutlet, () => form.fulfillment_method], loadCalendar);
const selectedDay = computed(() => calendar.value.find(day => day.date === form.requested_date) ?? null);
watch(() => form.requested_date, () => { if (!selectedDay.value?.slots.some(slot => slot.time === form.requested_time && slot.left !== 0)) form.requested_time = ''; });
const outletClosesAt = computed(() => props.outlets.find(outlet => outlet.id === effectiveOutlet.value)?.closes_at.replace(':', '.') ?? null);
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
function sameAsCustomer() { form.recipient_whatsapp = form.whatsapp; }
// Same host allowlist as the server (CheckoutRequest::isMapsUrl).
function isMapsUrl(value: string) {
    try {
        const url = new URL(value);
        if (url.protocol !== 'https:' || url.username || url.password || url.port) return false;
        const host = url.hostname.toLowerCase();
        if (['maps.app.goo.gl', 'maps.google.com', 'maps.google.co.id'].includes(host)) return true;
        return ['goo.gl', 'google.com', 'www.google.com', 'google.co.id', 'www.google.co.id'].includes(host) && url.pathname.startsWith('/maps');
    } catch {
        return false;
    }
}

const errorSummary = ref<HTMLElement | null>(null);
const errors = computed(() => form.errors as Record<string, string>);
const itemError = (index: number) => errors.value[`items.${index}.product_id`] ?? errors.value[`items.${index}.contents`] ?? errors.value[`items.${index}.quantity`];
const canSubmit = computed(() => !form.processing && pricesReady.value && form.items.length > 0 && cart.value.every(row => row.product));
// A field's message disappears as soon as the customer changes it.
for (const field of ['name', 'whatsapp', 'outlet_id', 'delivery_address', 'recipient_whatsapp', 'location_url', 'requested_date', 'requested_time'] as const) {
    watch(() => form[field], () => { if (form.errors[field]) form.clearErrors(field); });
}
// Checked here with visible messages: iOS Safari ignores the date's min and often blocks "required" fields silently.
function clientErrors(): Record<string, string> {
    const found: Record<string, string> = {};
    if (!form.name.trim()) found.name = 'Nama lengkap wajib diisi.';
    if (!/^[0-9+\s()-]{8,}$/.test(form.whatsapp.trim())) found.whatsapp = 'Masukkan nomor WhatsApp yang valid, misalnya 081234567890.';
    if (!form.outlet_id) found.outlet_id = form.fulfillment_method === 'delivery' ? 'Pilih outlet pengirim atau "Pilihkan outlet terdekat".' : 'Pilih outlet pickup.';
    if (form.fulfillment_method === 'delivery') {
        if (props.deliveryMinOrder && subtotal.value < props.deliveryMinOrder) found.fulfillment_method = `Minimum belanja untuk delivery ${rupiah(props.deliveryMinOrder)}. Tambah produk atau pilih pickup.`;
        if (!form.delivery_address.trim()) found.delivery_address = 'Alamat pengiriman wajib diisi.';
        if (!/^[0-9+\s()-]{8,}$/.test(form.recipient_whatsapp.trim())) found.recipient_whatsapp = 'Masukkan nomor WhatsApp penerima, misalnya 081234567890.';
        if (!isMapsUrl(form.location_url.trim())) found.location_url = 'Tempel link lokasi dari Google Maps (diawali https://maps.app.goo.gl/ atau https://www.google.com/maps/).';
    }
    if (!form.requested_date) found.requested_date = 'Pilih tanggal PO di kalender.';
    else if (!form.requested_time) found.requested_time = form.fulfillment_method === 'delivery' ? 'Pilih perkiraan jam kirim.' : 'Pilih jam pickup.';
    return found;
}
async function submit() {
    if (!canSubmit.value) return;
    const found = clientErrors();
    if (Object.keys(found).length) {
        form.clearErrors();
        form.setError(found);
        await nextTick();
        const first = document.getElementById(Object.keys(found)[0]);
        first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        first?.focus({ preventScroll: true });
        return;
    }
    form.transform(data => ({
        ...data, outlet_id: effectiveOutlet.value, nearest_outlet: data.fulfillment_method === 'delivery' && data.outlet_id === 'nearest',
        items: toRequest(data.items, productById.value),
    })).post('/order', { preserveScroll: true, onError: async () => { await nextTick(); errorSummary.value?.focus(); } });
}
function scrollToCart() { document.getElementById('cart')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
// The cart bar and drawer (CartPanel) edit the same lines.
const cartPanel = ref<InstanceType<typeof CartPanel> | null>(null);
const panelLines = computed<PanelLine[]>(() => cart.value.map(row => ({
    key: `${row.product_id}:${row.contents.join(',')}`, index: row.index, name: row.product?.name ?? 'Produk tidak tersedia',
    detail: [row.product ? categoryName(row.product.category_id) : '', row.product?.variant ?? ''].filter(Boolean).join(' · '),
    contentsText: row.contentsText, unit: row.unit, quantity: row.quantity, error: itemError(row.index),
})));
</script>
<template>
    <h1 class="text-4xl">Form order Orlena</h1>
    <p class="mt-4 max-w-2xl leading-relaxed text-chocolate/65">Pilih kategori, produk, dan jumlah, lalu lengkapi data pemesanan. Pesanan akan diperiksa tim Orlena terlebih dahulu; pembayaran dilakukan setelah produk, jadwal, dan total dikonfirmasi.</p>
    <p class="mt-3 text-sm"><a href="/order/tambah" class="font-bold underline"><i class="fa-solid fa-cart-plus" aria-hidden="true"></i> Sudah punya Order Code? Tambah ke pesanan yang belum dibayar</a></p>
    <div v-if="!products.length || !outlets.length" role="status" class="my-8 rounded-2xl bg-cream p-6"><h2 class="text-xl">Pemesanan belum tersedia</h2><p class="mt-2 text-sm">Produk dan outlet sedang disiapkan. Form dapat digunakan setelah pilihan tersedia.</p></div>

    <form class="mt-8 space-y-6 pb-28" novalidate @submit.prevent="submit">
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
                <label class="order-method" :class="{ 'is-disabled': !deliveryOutlets.length }"><input v-model="form.fulfillment_method" type="radio" value="delivery" class="sr-only" :disabled="!deliveryOutlets.length"><span><i class="fa-solid fa-motorcycle" aria-hidden="true"></i><strong>Delivery (Gojek/Grab)</strong><small>{{ deliveryOutlets.length ? 'Dikirim dari outlet pilihan Anda atau outlet terdekat.' : 'Sedang tidak tersedia.' }}</small></span></label>
            </fieldset>
            <p v-if="form.errors.fulfillment_method" class="admin-error-text text-sm" role="alert">{{ form.errors.fulfillment_method }}</p>
            <template v-if="form.fulfillment_method === 'pickup'">
                <Field id="outlet_id" label="Outlet pickup" :error="form.errors.outlet_id"><template #default="{ describedBy }"><select id="outlet_id" v-model="form.outlet_id" required :disabled="form.processing" :aria-describedby="describedBy" :aria-invalid="!!form.errors.outlet_id"><option value="" disabled>Pilih outlet</option><option v-for="outlet in outlets" :key="outlet.id" :value="outlet.id">{{ outlet.name }}</option></select></template></Field>
                <p v-if="pickupOutlet" class="admin-muted -mt-2 text-xs"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ pickupOutlet.address }}</p>
            </template>
            <template v-else>
                <Field id="outlet_id" label="Dikirim dari outlet" hint="Kurir Gojek/Grab dipesankan Orlena dari outlet ini. Tidak yakin? Pilih outlet terdekat, tim Orlena yang menentukan dari lokasi Anda." :error="form.errors.outlet_id ?? form.errors.fulfillment_method"><template #default="{ describedBy }"><select id="outlet_id" v-model="form.outlet_id" required :disabled="form.processing" :aria-describedby="describedBy" :aria-invalid="!!form.errors.outlet_id"><option value="" disabled>Pilih outlet</option><option v-if="nearestOutlet" value="nearest">Pilihkan outlet terdekat</option><option v-for="outlet in deliveryOutlets" :key="outlet.id" :value="outlet.id">{{ outlet.name }}</option></select></template></Field>
                <Field id="delivery_address" label="Alamat pengiriman lengkap" :error="form.errors.delivery_address"><template #default="{ describedBy }"><textarea id="delivery_address" v-model="form.delivery_address" required rows="3" maxlength="2000" autocomplete="street-address" placeholder="Nama jalan, nomor rumah, patokan" :aria-describedby="describedBy" :aria-invalid="!!form.errors.delivery_address"></textarea></template></Field>
                <Field id="location_url" label="Link lokasi Google Maps" hint="Buka Google Maps, tekan lama titik lokasi penerima, lalu Bagikan → Salin link, dan tempel di sini." :error="form.errors.location_url"><template #default="{ describedBy }"><input id="location_url" v-model.trim="form.location_url" type="url" inputmode="url" required maxlength="500" placeholder="https://maps.app.goo.gl/…" :aria-describedby="describedBy" :aria-invalid="!!form.errors.location_url"></template></Field>
                <Field id="recipient_whatsapp" label="Nomor WhatsApp penerima" hint="Dihubungi kurir saat mengantar." :error="form.errors.recipient_whatsapp"><template #default="{ describedBy }"><div class="flex flex-wrap gap-2"><input id="recipient_whatsapp" v-model="form.recipient_whatsapp" type="tel" required autocomplete="tel" placeholder="08…" maxlength="30" class="min-w-0 flex-1" :aria-describedby="describedBy" :aria-invalid="!!form.errors.recipient_whatsapp"><button type="button" class="admin-secondary" :disabled="!form.whatsapp" @click="sameAsCustomer">Sama dengan pemesan</button></div></template></Field>
                <p class="admin-alert admin-alert-info text-sm"><i class="fa-solid fa-clock" aria-hidden="true"></i> Jam kirim adalah <strong>perkiraan</strong>, bukan jam tiba yang dijamin. Kurir Gojek/Grab dipesankan Orlena, sehingga ada toleransi keterlambatan hingga <strong>{{ deliveryTolerance }} menit</strong> tanpa kompensasi. Ongkir mengikuti tarif aplikasi dan dikonfirmasi admin sebelum pembayaran.<template v-if="deliveryRadius"> Jangkauan delivery maksimal <strong>{{ deliveryRadius }} km</strong> dari outlet pengirim.</template><template v-if="deliveryMinOrder"> Minimum belanja <strong>{{ rupiah(deliveryMinOrder) }}</strong>.</template></p>
            </template>
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,23rem)_1fr] lg:items-start">
            <fieldset class="min-w-0 space-y-3" :aria-describedby="form.errors.requested_date ? 'requested_date-error' : undefined">
                <legend class="mb-1 text-sm font-bold">Tanggal PO</legend>
                <p class="admin-muted m-0 text-xs">Batas pemesanan {{ cutoffLabel }}. Paling lama 30 hari ke depan.</p>
                <p v-if="!effectiveOutlet" class="admin-muted m-0 rounded-xl bg-cream/60 p-3 text-sm">Pilih outlet dulu untuk melihat tanggal dan jam yang tersedia.</p>
                <p v-else-if="calendarState === 'error'" class="admin-error-text m-0 text-sm" role="alert">Jadwal belum berhasil dimuat. <button type="button" class="underline" @click="loadCalendar">Coba lagi</button></p>
                <div v-else id="requested_date" tabindex="-1">
                    <PoCalendar v-model="form.requested_date" :days="calendar" :disabled="calendarState === 'loading' || form.processing" />
                </div>
                <p v-if="form.errors.requested_date" id="requested_date-error" class="admin-error-text m-0 text-sm" role="alert">{{ form.errors.requested_date }}</p>
            </fieldset>
            <fieldset v-if="selectedDay" class="min-w-0 space-y-2 lg:pt-7">
                <legend class="mb-1 text-sm font-bold">{{ form.fulfillment_method === 'delivery' ? 'Perkiraan jam kirim (WITA)' : 'Jam pickup (WITA)' }}</legend>
                <div id="requested_time" tabindex="-1" class="flex flex-wrap gap-2">
                    <button v-for="slot in selectedDay.slots" :key="slot.time" type="button" class="po-slot" :aria-pressed="form.requested_time === slot.time" :disabled="slot.left === 0 || form.processing" @click="form.requested_time = slot.time">
                        {{ slot.time.replace(':', '.') }}<small>{{ slot.left === null ? 'perkiraan' : slot.left === 0 ? 'penuh' : `sisa ${slot.left}` }}</small>
                    </button>
                </div>
                <p v-if="form.fulfillment_method === 'pickup' && outletClosesAt" class="admin-muted m-0 text-xs">Pesanan disimpan di outlet sampai outlet tutup (pukul {{ outletClosesAt }} WITA) di hari pickup.</p>
                <p v-if="form.errors.requested_time" class="admin-error-text m-0 text-sm" role="alert">{{ form.errors.requested_time }}</p>
            </fieldset>
            </div>
            <Field v-if="hasHamper || hasCard" id="card_message" :label="hasHamper ? 'Kartu ucapan hampers (opsional)' : 'Tulisan kartu ucapan (opsional)'" :hint="`Ditulis di kartu ucapan yang disertakan bersama pesanan. ${form.card_message.length}/300 karakter.`" :error="form.errors.card_message"><template #default="{ describedBy }"><textarea id="card_message" v-model="form.card_message" rows="3" maxlength="300" placeholder="Contoh: Selamat Hari Raya, semoga selalu diberi berkah. Dari keluarga Sinta." :aria-describedby="describedBy" :aria-invalid="!!form.errors.card_message"></textarea></template></Field>
            <Field id="customer_note" label="Catatan (opsional)" :error="form.errors.customer_note"><template #default="{ describedBy }"><textarea id="customer_note" v-model="form.customer_note" rows="3" maxlength="2000" placeholder="Misalnya tulisan di kue atau permintaan khusus" :aria-describedby="describedBy"></textarea></template></Field>
        </section>

        <!-- Step 4: what will be sent, then the submit button. -->
        <section id="cart" class="admin-card space-y-5" aria-labelledby="step-summary">
            <div class="flex items-center justify-between gap-3">
                <h2 id="step-summary" class="text-xl"><span class="order-step">4</span>Ringkasan &amp; kirim</h2>
                <button v-if="cart.length" type="button" class="admin-action" @click="cartPanel?.open()"><i class="fa-solid fa-pen" aria-hidden="true"></i>Ubah keranjang</button>
            </div>
            <p v-if="!cart.length" class="admin-muted rounded-xl bg-cream/60 p-4 text-center text-sm"><i class="fa-solid fa-basket-shopping mb-2 block text-2xl text-chocolate/25" aria-hidden="true"></i>Keranjang masih kosong. Pilih produk di langkah 1.</p>
            <ul v-else class="m-0 list-none divide-y divide-chocolate/10 p-0">
                <li v-for="row in cart" :key="`${row.product_id}:${row.contents.join(',')}`" class="flex justify-between gap-3 py-3 text-sm">
                    <div class="min-w-0">
                        <p class="m-0 font-bold">{{ row.quantity }} × {{ row.product?.name ?? 'Produk tidak tersedia' }}</p>
                        <p class="m-0 text-xs text-matcha">{{ row.product ? categoryName(row.product.category_id) : '' }}{{ row.product?.variant ? ` · ${row.product.variant}` : '' }}</p>
                        <p v-if="row.contentsText" class="m-0 mt-0.5 text-xs text-chocolate/75"><i class="fa-solid fa-gift" aria-hidden="true"></i> Isi: {{ row.contentsText }}</p>
                        <p v-if="itemError(row.index)" class="admin-error-text m-0 mt-1 text-xs" role="alert">{{ itemError(row.index) }}</p>
                    </div>
                    <span class="shrink-0 font-bold tabular-nums">{{ rupiah(row.unit * row.quantity) }}</span>
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
            <details class="rounded-xl bg-cream/60 p-3 text-xs leading-relaxed">
                <summary class="cursor-pointer font-bold">Ketentuan pesanan</summary>
                <ul class="mb-0 mt-2 list-disc space-y-1 pl-5">
                    <li>Pesanan diproduksi sesuai tanggal bila lunas sebelum {{ cutoffLabel }}. Pembayaran setelah itu sampai {{ paymentClose }} dipindah ke tanggal buka berikutnya; belum dibayar pada {{ paymentClose }} dibatalkan otomatis.</li>
                    <li>Sebelum bayar, pesanan bisa diubah atau dibatalkan lewat WhatsApp. Setelah lunas, pesanan tidak bisa dibatalkan.</li>
                    <li>Pickup: pesanan disimpan sampai outlet tutup. Pesanan yang tidak diambil dijadwalkan ulang ke tanggal baru, paling lambat 2 hari setelah tanggal pickup awal.</li>
                    <li>Delivery: jam kirim adalah perkiraan, dengan toleransi keterlambatan hingga {{ deliveryTolerance }} menit tanpa kompensasi.</li>
                    <li>Produk salah atau rusak: refund dengan bukti foto, paling lambat 2 jam setelah pesanan diterima.</li>
                </ul>
            </details>
            <button class="admin-primary w-full" :disabled="!canSubmit">{{ form.processing ? 'Menyimpan pesanan…' : 'Kirim permintaan PO' }}</button>
            <p class="admin-muted text-xs leading-relaxed">{{ form.fulfillment_method === 'delivery' ? 'Total awal belum termasuk ongkir. ' : '' }}Ini adalah permintaan PO; ketersediaan produk dan jadwal dikonfirmasi admin. Setelah tersimpan, Anda mendapat Order Code untuk dilanjutkan via WhatsApp.</p>
        </section>

        <CartPanel ref="cartPanel" :lines="panelLines" :subtotal="subtotal" :disabled="form.processing" @quantity="(index, quantity) => { form.items[index].quantity = quantity; }" @remove="index => { form.items.splice(index, 1); form.clearErrors(); }">
            <template #action="{ close }"><button type="button" class="admin-primary flex-1" @click="close(); scrollToCart()">Lanjut ke ringkasan</button></template>
        </CartPanel>
    </form>
</template>

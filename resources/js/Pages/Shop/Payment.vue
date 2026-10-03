<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import QRCode from 'qrcode';
import ShopLayout from '../../Layouts/ShopLayout.vue';
import OrderSummary from '../../Components/OrderSummary.vue';
import type { OrderItem, OrderStatus, PaymentStatus } from '../../Types/ordering';
import { rupiah } from '../../Support/money';
defineOptions({ layout: ShopLayout });
interface PayOrder {
    order_code: string; order_status: OrderStatus; payment_status: PaymentStatus; fulfillment_method: 'pickup' | 'delivery'; outlet_name_snapshot: string;
    requested_date: string; requested_time: string | null; subtotal: number; delivery_fee: number | null; total: number;
    items: Pick<OrderItem, 'id' | 'product_name_snapshot' | 'category_snapshot' | 'variant_snapshot' | 'quantity' | 'subtotal'>[];
}
interface QrisPayment { qr: string; amount: number; expires_at: string | null; demo?: boolean }
const props = defineProps<{ order: PayOrder; payment: QrisPayment | null; whatsappUrl: string | null }>();

const paymentStatus = ref<PaymentStatus>(props.order.payment_status);
const orderStatus = ref<OrderStatus>(props.order.order_status);
const payment = ref<QrisPayment | null>(props.payment);
const paid = computed(() => ['paid', 'refunded'].includes(paymentStatus.value));

// The QR is drawn in the browser from DOKU's QRIS string.
const qrImage = ref<string | null>(null);
watch(() => payment.value?.qr, async qr => {
    qrImage.value = qr ? await QRCode.toDataURL(qr, { width: 640, margin: 2, errorCorrectionLevel: 'M', color: { dark: '#1b0102', light: '#ffffff' } }) : null;
}, { immediate: true });

// Countdown to the payment deadline.
const now = ref(Date.now());
const remaining = computed(() => {
    if (!payment.value?.expires_at) return null;
    const ms = new Date(payment.value.expires_at).getTime() - now.value;
    if (ms <= 0) return 'Waktu pembayaran habis';
    const h = Math.floor(ms / 3600000), m = Math.floor((ms % 3600000) / 60000), s = Math.floor((ms % 60000) / 1000);
    return `${h > 0 ? `${h} jam ` : ''}${m} menit ${String(s).padStart(2, '0')} detik`;
});
const deadline = computed(() => payment.value?.expires_at
    ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Makassar' }).format(new Date(payment.value.expires_at)) + ' WITA' : null);

// Poll the status while a QR is open; the server asks DOKU and marks the order paid.
let poll: number | undefined;
let tick: number | undefined;
async function check() {
    if (document.hidden) return;
    try {
        const response = await fetch(`/bayar/${props.order.order_code}/status`, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const data = await response.json();
        paymentStatus.value = data.payment_status;
        orderStatus.value = data.order_status;
        payment.value = data.payment;
        if (paid.value || !data.payment) window.clearInterval(poll);
    } catch {
        // Offline for a moment: try again on the next tick.
    }
}
onMounted(() => {
    tick = window.setInterval(() => { now.value = Date.now(); }, 1000);
    if (payment.value && !paid.value) poll = window.setInterval(check, 5000);
});
onBeforeUnmount(() => { window.clearInterval(poll); window.clearInterval(tick); });
// The page reloads after the demo payment; keep local state in step with the new props.
watch(() => [props.order.payment_status, props.order.order_status, props.payment] as const, ([pay, status, open]) => {
    paymentStatus.value = pay; orderStatus.value = status; payment.value = open;
});

// Demo mode: stands in for paying the QR with an e-wallet.
const simulating = ref(false);
function simulate() {
    router.post(`/bayar/${props.order.order_code}/simulasi`, {}, { preserveScroll: true, onStart: () => { simulating.value = true; }, onFinish: () => { simulating.value = false; } });
}

const schedule = computed(() => new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Makassar' })
    .format(new Date(`${props.order.requested_date}T12:00:00+08:00`)) + (props.order.requested_time ? `, ${props.order.requested_time.slice(0, 5)} WITA` : ''));
const message = computed(() => {
    if (orderStatus.value === 'cancelled') return 'Pesanan ini dibatalkan. Hubungi Orlena bila ada pertanyaan.';
    if (orderStatus.value === 'pending_review') return 'Pesanan masih diperiksa tim Orlena. Kode pembayaran akan tersedia setelah pesanan dikonfirmasi.';
    return 'Kode pembayaran belum tersedia atau sudah kedaluwarsa. Hubungi Orlena untuk kode baru.';
});
</script>
<template>
    <div class="mx-auto max-w-xl">
        <p class="admin-eyebrow">Pembayaran</p>
        <h1 class="break-all text-2xl sm:text-3xl">{{ order.order_code }}</h1>

        <section v-if="paid" class="admin-card mt-6 text-center" role="status" aria-live="polite">
            <span class="mx-auto flex size-16 items-center justify-center rounded-full bg-matcha text-2xl text-white"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
            <h2 class="mt-4 text-2xl">Pembayaran diterima</h2>
            <p class="admin-muted mt-2 text-sm">Terima kasih! Pesanan Anda sedang disiapkan sesuai jadwal.</p>
            <Link :href="`/cek-pesanan?code=${order.order_code}`" class="admin-secondary mt-5"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Cek status pesanan</Link>
        </section>

        <section v-else-if="payment" class="admin-card mt-6" aria-labelledby="qris-title">
            <p v-if="payment.demo" class="mb-4 rounded-xl border border-dashed border-chocolate/30 bg-almond/40 p-4 text-sm" role="note"><strong>Mode demo.</strong> QR ini hanya contoh dan tidak bisa dibayar. Tekan <strong>Simulasikan pembayaran berhasil</strong> untuk melihat alur setelah pelanggan membayar.</p>
            <div class="flex items-center justify-between gap-3">
                <h2 id="qris-title" class="m-0 text-xl">Bayar dengan QRIS</h2>
                <span class="rounded-full bg-cream px-3 py-1 text-xs font-bold">QRIS</span>
            </div>
            <p class="mt-1 text-sm text-chocolate/70">Total yang harus dibayar</p>
            <p class="m-0 text-3xl font-bold tabular-nums">{{ rupiah(payment.amount) }}</p>
            <div class="mx-auto mt-5 w-full max-w-72 rounded-2xl border border-chocolate/10 bg-white p-3">
                <img v-if="qrImage" :src="qrImage" :alt="`Kode QRIS untuk membayar ${rupiah(payment.amount)}`" class="aspect-square w-full">
                <div v-else class="flex aspect-square items-center justify-center"><span class="page-loader-spinner" aria-hidden="true"></span></div>
            </div>
            <p class="mt-4 text-center text-sm" role="timer" aria-live="off">Bayar sebelum <strong>{{ deadline }}</strong><br><span class="text-chocolate/65">Sisa waktu: {{ remaining }}</span></p>
            <a v-if="qrImage" :href="qrImage" :download="`QRIS-${order.order_code}.png`" class="admin-secondary mt-4 w-full"><i class="fa-solid fa-download" aria-hidden="true"></i>Simpan QR</a>
            <ol class="mt-5 list-decimal space-y-1 pl-5 text-sm text-chocolate/80">
                <li>Buka aplikasi e-wallet atau m-banking yang mendukung QRIS (GoPay, OVO, DANA, ShopeePay, BCA, dan lainnya).</li>
                <li>Pindai kode di atas. Dari HP yang sama: tekan <strong>Simpan QR</strong>, lalu unggah gambarnya di menu scan aplikasi.</li>
                <li>Periksa nama merchant dan nominal, lalu bayar.</li>
                <li>Halaman ini otomatis berubah setelah pembayaran diterima.</li>
            </ol>
            <p class="mt-4 flex items-center gap-2 text-xs text-chocolate/60" aria-live="polite"><span class="page-loader-spinner" style="width: .9rem; height: .9rem; border-width: 2px" aria-hidden="true"></span> Menunggu pembayaran…</p>
            <button v-if="payment.demo" type="button" class="admin-primary mt-4 w-full" :disabled="simulating" @click="simulate"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>{{ simulating ? 'Memproses…' : 'Simulasikan pembayaran berhasil' }}</button>
        </section>

        <section v-else class="admin-card mt-6" role="status">
            <h2 class="text-xl">Pembayaran belum tersedia</h2>
            <p class="mt-2 text-sm text-chocolate/75">{{ message }}</p>
        </section>

        <section class="admin-card mt-6" aria-labelledby="summary-title">
            <h2 id="summary-title" class="mb-5 text-xl">Ringkasan pesanan</h2>
            <OrderSummary :order="order" />
            <dl class="mt-6 space-y-3 border-t border-chocolate/10 pt-5 text-sm">
                <div><dt class="font-bold">Penerimaan</dt><dd>{{ order.fulfillment_method === 'pickup' ? `Pickup di ${order.outlet_name_snapshot}` : `Delivery (Gojek/Grab) dari ${order.outlet_name_snapshot}` }}</dd></div>
                <div><dt class="font-bold">Jadwal</dt><dd>{{ schedule }}</dd></div>
            </dl>
        </section>

        <a v-if="whatsappUrl" :href="whatsappUrl" target="_blank" rel="noopener noreferrer" class="admin-secondary mt-6 w-full"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i>Tanya Orlena via WhatsApp</a>
        <p class="mt-4 text-xs text-chocolate/60">Pembayaran diproses oleh DOKU. Orlena tidak pernah meminta transfer ke rekening pribadi.</p>
    </div>
</template>

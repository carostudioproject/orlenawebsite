<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
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
    items: Pick<OrderItem, 'id' | 'parent_item_id' | 'product_name_snapshot' | 'category_snapshot' | 'variant_snapshot' | 'quantity' | 'subtotal'>[];
}
type Method = 'va_bca' | 'qris';
interface OpenPayment { qr: string | null; va: string | null; method: Method | 'checkout'; amount: number; expires_at: string | null; demo?: boolean; checkout_url?: string | null }
interface MethodOption { value: Method; label: string }
const props = defineProps<{ order: PayOrder; payment: OpenPayment | null; methods: MethodOption[]; dueAt: string | null; whatsappUrl: string | null }>();

const paymentStatus = ref<PaymentStatus>(props.order.payment_status);
const orderStatus = ref<OrderStatus>(props.order.order_status);
const payment = ref<OpenPayment | null>(props.payment);
const methods = ref<MethodOption[]>(props.methods);
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
const witaDate = (value: string | null | undefined) => (value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Makassar' }).format(new Date(value)) + ' WITA' : null);
const deadline = computed(() => witaDate(payment.value?.expires_at));
const dueLabel = computed(() => witaDate(props.dueAt));

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
        methods.value = data.methods ?? [];
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
watch(() => [props.order.payment_status, props.order.order_status, props.payment, props.methods] as const, ([pay, status, open, options]) => {
    paymentStatus.value = pay; orderStatus.value = status; payment.value = open; methods.value = options;
    window.clearInterval(poll);
    if (open && !paid.value) poll = window.setInterval(check, 5000);
});

// VA BCA or QRIS: switching closes the previous code at DOKU and creates the new one.
const choosing = ref<Method | null>(null);
const chooseError = computed(() => { const errors = usePage().props.errors as Record<string, string>; return errors.method ?? errors.payment ?? null; });
function choose(method: Method) {
    if (choosing.value || payment.value?.method === method) return;
    router.post(`/bayar/${props.order.order_code}/metode`, { method }, { preserveScroll: true, onStart: () => { choosing.value = method; }, onFinish: () => { choosing.value = null; } });
}

const copied = ref<string | null>(null);
async function copy(label: string, text: string) {
    try { await navigator.clipboard.writeText(text); copied.value = label; window.setTimeout(() => { copied.value = null; }, 1600); } catch { copied.value = null; }
}
const vaGroups = computed(() => payment.value?.va?.replace(/\s+/g, '').replace(/(\d{4})(?=\d)/g, '$1 ') ?? '');

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

        <template v-else>
            <section v-if="methods.length > 1 || (!payment && methods.length)" class="mt-6" aria-labelledby="method-title">
                <h2 id="method-title" class="m-0 text-base">Pilih metode pembayaran</h2>
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <button v-for="option in methods" :key="option.value" type="button" :aria-pressed="payment?.method === option.value" :disabled="choosing !== null"
                        class="flex min-h-14 items-center justify-center gap-2 rounded-2xl border-2 px-3 py-2 text-sm font-bold transition"
                        :class="payment?.method === option.value ? 'border-chocolate bg-chocolate text-white' : 'border-chocolate/15 bg-white hover:border-chocolate/40'"
                        @click="choose(option.value)">
                        <span v-if="choosing === option.value" class="page-loader-spinner" style="width: .9rem; height: .9rem; border-width: 2px" aria-hidden="true"></span>
                        <i v-else class="fa-solid" :class="option.value === 'va_bca' ? 'fa-building-columns' : 'fa-qrcode'" aria-hidden="true"></i>{{ option.label }}
                    </button>
                </div>
                <p v-if="chooseError" role="alert" class="admin-error-text mt-2 text-sm">{{ chooseError }}</p>
                <p v-else-if="payment && methods.length > 1" class="admin-muted mt-2 text-xs">Ganti metode kapan saja sebelum membayar. Kode sebelumnya otomatis tidak berlaku.</p>
            </section>

            <section v-if="payment?.checkout_url" class="admin-card mt-6" aria-labelledby="va-title">
                <h2 id="va-title" class="m-0 text-xl">Bayar via Virtual Account</h2>
                <p class="mt-1 text-sm text-chocolate/70">Total yang harus dibayar</p>
                <p class="m-0 text-3xl font-bold tabular-nums">{{ rupiah(payment.amount) }}</p>
                <p class="mt-4 text-sm" role="timer" aria-live="off">Bayar sebelum <strong>{{ deadline }}</strong><br><span class="text-chocolate/65">Sisa waktu: {{ remaining }}</span></p>
                <a :href="payment.checkout_url" class="admin-primary mt-4 w-full"><i class="fa-solid fa-building-columns" aria-hidden="true"></i>Pilih bank dan bayar</a>
                <p class="admin-muted mt-3 text-xs">Anda akan diarahkan ke halaman pembayaran DOKU untuk memilih bank (BCA, Mandiri, BRI, BNI, dan lainnya) dan mendapatkan nomor Virtual Account. Status pesanan berubah otomatis setelah pembayaran diterima.</p>
            </section>

            <section v-else-if="payment?.va" class="admin-card mt-6" aria-labelledby="vabca-title">
                <p v-if="payment.demo" class="mb-4 rounded-xl border border-dashed border-chocolate/30 bg-almond/40 p-4 text-sm" role="note"><strong>Mode demo.</strong> Nomor VA ini hanya contoh dan tidak bisa dibayar. Tekan <strong>Simulasikan pembayaran berhasil</strong> untuk melihat alur setelah pelanggan membayar.</p>
                <div class="flex items-center justify-between gap-3">
                    <h2 id="vabca-title" class="m-0 text-xl">Virtual Account BCA</h2>
                    <span class="rounded-full bg-cream px-3 py-1 text-xs font-bold">BCA</span>
                </div>
                <p class="mt-4 text-sm text-chocolate/70">Nomor Virtual Account</p>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="m-0 break-all font-mono text-2xl font-bold tracking-wide tabular-nums">{{ vaGroups }}</p>
                    <button type="button" class="admin-action" @click="copy('va', vaGroups.replace(/ /g, ''))"><i class="fa-solid fa-copy" aria-hidden="true"></i>{{ copied === 'va' ? 'Tersalin' : 'Salin' }}</button>
                </div>
                <p class="mt-4 text-sm text-chocolate/70">Total yang harus dibayar (harus sama persis)</p>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="m-0 text-3xl font-bold tabular-nums">{{ rupiah(payment.amount) }}</p>
                    <button type="button" class="admin-action" @click="copy('amount', String(payment.amount))"><i class="fa-solid fa-copy" aria-hidden="true"></i>{{ copied === 'amount' ? 'Tersalin' : 'Salin' }}</button>
                </div>
                <p class="mt-4 text-sm" role="timer" aria-live="off">Bayar sebelum <strong>{{ deadline }}</strong><br><span class="text-chocolate/65">Sisa waktu: {{ remaining }}</span></p>
                <span class="sr-only" aria-live="polite">{{ copied ? 'Disalin ke clipboard' : '' }}</span>
                <details class="mt-5 rounded-xl border border-chocolate/10 p-4 text-sm" open>
                    <summary class="cursor-pointer font-bold">Cara bayar lewat BCA mobile (m-BCA)</summary>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-chocolate/80">
                        <li>Buka BCA mobile, pilih <strong>m-Transfer</strong> lalu <strong>BCA Virtual Account</strong>.</li>
                        <li>Masukkan nomor Virtual Account di atas.</li>
                        <li>Periksa nama dan nominal, lalu masukkan PIN.</li>
                    </ol>
                </details>
                <details class="mt-3 rounded-xl border border-chocolate/10 p-4 text-sm">
                    <summary class="cursor-pointer font-bold">Lewat ATM BCA atau KlikBCA</summary>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-chocolate/80">
                        <li>ATM: <strong>Transaksi Lainnya</strong> &gt; <strong>Transfer</strong> &gt; <strong>ke Rek BCA Virtual Account</strong>.</li>
                        <li>KlikBCA: <strong>Transfer Dana</strong> &gt; <strong>Transfer ke BCA Virtual Account</strong>.</li>
                        <li>Masukkan nomor Virtual Account, periksa nominal, lalu konfirmasi.</li>
                    </ol>
                </details>
                <p class="admin-muted mt-3 text-xs">Dari bank lain: pilih transfer antarbank ke BCA dengan nomor Virtual Account di atas (dapat dikenai biaya bank).</p>
                <p class="mt-4 flex items-center gap-2 text-xs text-chocolate/60" aria-live="polite"><span class="page-loader-spinner" style="width: .9rem; height: .9rem; border-width: 2px" aria-hidden="true"></span> Menunggu pembayaran… Halaman ini otomatis berubah setelah pembayaran diterima.</p>
                <button v-if="payment.demo" type="button" class="admin-primary mt-4 w-full" :disabled="simulating" @click="simulate"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>{{ simulating ? 'Memproses…' : 'Simulasikan pembayaran berhasil' }}</button>
            </section>

            <section v-else-if="payment?.qr" class="admin-card mt-6" aria-labelledby="qris-title">
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

            <section v-else-if="methods.length" class="admin-card mt-6" role="status">
                <h2 class="text-xl">Pesanan sudah dikonfirmasi</h2>
                <p class="mt-1 text-sm text-chocolate/70">Total yang harus dibayar</p>
                <p class="m-0 text-3xl font-bold tabular-nums">{{ rupiah(order.total) }}</p>
                <p class="mt-3 text-sm text-chocolate/75">Pilih <strong>Virtual Account BCA</strong> atau <strong>QRIS</strong> di atas. Nomor VA atau kode QR dibuat setelah Anda memilih.</p>
                <p v-if="dueLabel" class="mt-2 text-sm">Bayar sebelum <strong>{{ dueLabel }}</strong></p>
            </section>

            <section v-else class="admin-card mt-6" role="status">
                <h2 class="text-xl">Pembayaran belum tersedia</h2>
                <p class="mt-2 text-sm text-chocolate/75">{{ message }}</p>
            </section>
        </template>

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

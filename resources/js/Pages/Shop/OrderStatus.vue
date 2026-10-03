<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ShopLayout from '../../Layouts/ShopLayout.vue';
import OrderSummary from '../../Components/OrderSummary.vue';
import type { OrderItem, OrderStatus as Status, PaymentStatus } from '../../Types/ordering';
import { customerOrderStatusLabel, customerPaymentStatusLabel } from '../../Support/orderStatus';
defineOptions({ layout: ShopLayout });
interface TrackedOrder {
    order_code: string; order_status: Status; payment_status: PaymentStatus; fulfillment_method: 'pickup' | 'delivery'; outlet_name_snapshot: string;
    requested_date: string; requested_time: string | null; subtotal: number; delivery_fee: number | null; total: number; created_at: string;
    items: Pick<OrderItem, 'id' | 'product_name_snapshot' | 'category_snapshot' | 'variant_snapshot' | 'quantity' | 'subtotal'>[];
}
const props = defineProps<{ order: TrackedOrder; payment: { url: string; expires_at: string | null } | null; whatsappUrl: string | null }>();

const dateLabel = (value: string, time?: string | null) => new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Makassar' })
    .format(new Date(`${value}T12:00:00+08:00`)) + (time ? `, ${time.slice(0, 5)} WITA` : '');
const expires = computed(() => props.payment?.expires_at ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Makassar' }).format(new Date(props.payment.expires_at)) + ' WITA' : null);

// Progress steps; delivery orders have an extra "Dikirim" step.
const paid = computed(() => ['paid', 'refunded'].includes(props.order.payment_status));
const steps = computed(() => {
    const flow: Status[] = ['processing', 'ready', ...(props.order.fulfillment_method === 'delivery' ? ['delivering' as Status] : []), 'completed'];
    const reached = flow.indexOf(props.order.order_status);
    return [
        { label: 'Pesanan diterima', done: true },
        { label: 'Dikonfirmasi staff', done: props.order.order_status !== 'pending_review' },
        { label: 'Lunas', done: paid.value },
        { label: 'Diproses', done: reached >= 0 },
        { label: props.order.fulfillment_method === 'pickup' ? 'Siap diambil' : 'Siap dikirim', done: reached >= 1 },
        ...(props.order.fulfillment_method === 'delivery' ? [{ label: 'Dikirim', done: reached >= 2 }] : []),
        { label: 'Selesai', done: props.order.order_status === 'completed' },
    ];
});
const current = computed(() => steps.value.findIndex(step => !step.done));
const cancelled = computed(() => props.order.order_status === 'cancelled');
const nextHint = computed(() => {
    if (cancelled.value) return 'Pesanan ini dibatalkan. Hubungi Orlena bila ada pertanyaan.';
    if (props.order.order_status === 'pending_review') return 'Tim Orlena sedang memeriksa produk, jadwal, dan ongkir. Link pembayaran akan dikirim melalui WhatsApp.';
    if (props.payment) return 'Pesanan sudah dikonfirmasi. Silakan selesaikan pembayaran sebelum batas waktu.';
    if (!paid.value) return 'Pesanan sudah dikonfirmasi. Silakan lakukan pembayaran sesuai instruksi dari Orlena melalui WhatsApp.';
    if (props.order.order_status === 'completed') return 'Pesanan selesai. Terima kasih sudah memesan di Orlena!';
    return 'Pembayaran diterima. Pesanan Anda sedang disiapkan sesuai jadwal.';
});
</script>
<template>
    <div class="mx-auto max-w-2xl">
        <Link href="/cek-pesanan" class="admin-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Cek pesanan lain</Link>
        <p class="admin-eyebrow mt-5">Status pesanan</p>
        <h1 class="break-all text-3xl sm:text-4xl">{{ order.order_code }}</h1>
        <div class="my-6 rounded-2xl p-6" :class="cancelled ? 'bg-rose/30' : 'bg-almond/60'">
            <p class="m-0 text-xl font-bold">{{ customerOrderStatusLabel(order.order_status) }}</p>
            <p class="m-0 mt-1 text-sm">{{ customerPaymentStatusLabel(order.payment_status) }}</p>
            <p class="m-0 mt-3 text-sm leading-relaxed">{{ nextHint }}</p>
            <a v-if="payment" :href="payment.url" target="_blank" rel="noopener noreferrer" class="admin-primary mt-4 w-full"><i class="fa-solid fa-credit-card" aria-hidden="true"></i>Bayar sekarang</a>
            <p v-if="payment && expires" class="m-0 mt-2 text-xs text-chocolate/70">Link berlaku sampai {{ expires }}.</p>
        </div>

        <section v-if="!cancelled" class="admin-card mb-6" aria-labelledby="progress-title">
            <h2 id="progress-title" class="mb-4 text-xl">Progres</h2>
            <ol class="m-0 list-none space-y-3 p-0">
                <li v-for="(step, index) in steps" :key="step.label" class="flex items-center gap-3 text-sm" :aria-current="index === current ? 'step' : undefined">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs" :class="step.done ? 'bg-matcha text-white' : index === current ? 'border-2 border-chocolate' : 'border border-chocolate/25'">
                        <i v-if="step.done" class="fa-solid fa-check" aria-hidden="true"></i><span v-else aria-hidden="true">{{ index + 1 }}</span>
                    </span>
                    <span :class="step.done || index === current ? 'font-bold' : 'text-chocolate/60'">{{ step.label }}<span class="sr-only">{{ step.done ? ' (selesai)' : index === current ? ' (tahap saat ini)' : '' }}</span></span>
                </li>
            </ol>
        </section>

        <section class="admin-card" aria-labelledby="order-title">
            <h2 id="order-title" class="mb-5 text-xl">Detail pesanan</h2>
            <OrderSummary :order="order" />
            <dl class="mt-6 space-y-3 border-t border-chocolate/10 pt-5 text-sm">
                <div><dt class="font-bold">Penerimaan</dt><dd>{{ order.fulfillment_method === 'pickup' ? `Pickup di ${order.outlet_name_snapshot}` : `Delivery (Gojek/Grab) dari ${order.outlet_name_snapshot}` }}</dd></div>
                <div><dt class="font-bold">Jadwal</dt><dd>{{ dateLabel(order.requested_date, order.requested_time) }}</dd></div>
            </dl>
        </section>

        <a v-if="whatsappUrl" :href="whatsappUrl" target="_blank" rel="noopener noreferrer" class="admin-secondary mt-6 w-full"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i>Tanya Orlena via WhatsApp</a>
        <p class="mt-4 text-xs text-chocolate/60">Demi privasi, nama, nomor WhatsApp, dan alamat tidak ditampilkan di halaman ini.</p>
    </div>
</template>

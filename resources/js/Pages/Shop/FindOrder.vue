<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import ShopLayout from '../../Layouts/ShopLayout.vue';
import Field from '../../Components/Field.vue';
defineOptions({ layout: ShopLayout });
const props = defineProps<{ code: string }>();
const form = useForm({ order_code: props.code, whatsapp: '' });
function submit() { form.post('/order/tambah', { preserveScroll: true }); }
</script>
<template>
    <div class="mx-auto max-w-lg">
        <p class="admin-eyebrow">Tambah pesanan</p>
        <h1 class="text-4xl">Tambah ke pesanan Anda</h1>
        <p class="mt-4 leading-relaxed text-chocolate/70">Masukkan Order Code dan nomor WhatsApp yang dipakai saat memesan. Penambahan hanya bisa dilakukan selama pesanan belum dikonfirmasi admin dan sebelum batas {{ $page.props.poCutoff }}.</p>
        <form class="admin-card mt-7 space-y-5" @submit.prevent="submit">
            <Field id="order_code" label="Order Code" hint="Contoh: ORL-260925-AB12CD34EF" :error="form.errors.order_code"><template #default="{ describedBy }"><input id="order_code" v-model="form.order_code" required maxlength="40" autocapitalize="characters" autocomplete="off" spellcheck="false" :aria-describedby="describedBy" :aria-invalid="!!form.errors.order_code" @input="form.order_code = form.order_code.toUpperCase()"></template></Field>
            <Field id="whatsapp" label="Nomor WhatsApp saat memesan" :error="form.errors.whatsapp"><template #default="{ describedBy }"><input id="whatsapp" v-model="form.whatsapp" type="tel" required autocomplete="tel" placeholder="08… atau +62…" maxlength="30" :aria-describedby="describedBy" :aria-invalid="!!form.errors.whatsapp"></template></Field>
            <button class="admin-primary w-full" :disabled="form.processing"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>{{ form.processing ? 'Memeriksa…' : 'Cari pesanan' }}</button>
        </form>
        <p class="admin-muted mt-5 text-sm">Belum punya pesanan? <Link href="/order" class="font-bold underline">Buat pesanan baru</Link>. Pesanan yang sudah dikonfirmasi atau dibayar hanya dapat diubah melalui admin Orlena.</p>
    </div>
</template>

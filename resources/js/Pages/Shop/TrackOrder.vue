<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import ShopLayout from '../../Layouts/ShopLayout.vue';
import Field from '../../Components/Admin/Field.vue';
defineOptions({ layout: ShopLayout });
const props = defineProps<{ code: string }>();
const form = useForm({ order_code: props.code });
function submit() { form.post('/cek-pesanan', { preserveScroll: true }); }
</script>
<template>
    <div class="mx-auto max-w-lg">
        <p class="admin-eyebrow">Cek pesanan</p>
        <h1 class="text-4xl">Cek status pesanan</h1>
        <p class="mt-4 leading-relaxed text-chocolate/70">Masukkan Order Code yang Anda terima saat memesan untuk melihat status, produk, dan jadwal pesanan tersebut.</p>
        <form class="admin-card mt-7 space-y-5" @submit.prevent="submit">
            <Field id="order_code" label="Order Code" hint="Contoh: ORL-260925-AB12CD34EF" :error="form.errors.order_code"><template #default="{ describedBy }"><input id="order_code" v-model="form.order_code" required maxlength="40" autocapitalize="characters" autocomplete="off" spellcheck="false" :aria-describedby="describedBy" :aria-invalid="!!form.errors.order_code" @input="form.order_code = form.order_code.toUpperCase()"></template></Field>
            <button class="admin-primary w-full" :disabled="form.processing"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>{{ form.processing ? 'Mencari…' : 'Cek pesanan' }}</button>
        </form>
        <p class="admin-muted mt-5 text-sm">Demi privasi, halaman ini tidak menampilkan nama, nomor WhatsApp, maupun alamat. Ingin menambah produk? Gunakan <Link href="/order/tambah" class="font-bold underline">Tambah pesanan</Link>. Belum punya pesanan? <Link href="/order" class="font-bold underline">Buat pesanan baru</Link>.</p>
    </div>
</template>

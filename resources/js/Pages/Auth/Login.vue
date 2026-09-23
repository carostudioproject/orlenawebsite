<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Field from '../../Components/Admin/Field.vue';
const form = useForm({ username: '', password: '' });
function submit() { form.post('/admin/login', { onFinish: () => form.reset('password') }); }
</script>
<template>
    <Head title="Staff login · Orlena"><meta head-key="robots" name="robots" content="noindex,nofollow" /></Head>
    <main class="admin-shell flex min-h-screen items-center justify-center bg-cream p-5">
        <section class="w-full max-w-md rounded-3xl bg-white p-7 shadow-sm sm:p-10">
            <a href="/"><img src="/assets/images/Orlena-Logo.png" alt="Orlena" class="mb-9 h-12 w-auto"></a>
            <h1 class="text-3xl font-bold">Welcome back</h1><p class="mt-3 text-sm text-chocolate/65">Log in to manage Orlena operations.</p>
            <form class="mt-7 space-y-5" @submit.prevent="submit">
                <Field id="username" label="Username" :error="form.errors.username"><template #default="{ describedBy }"><input id="username" v-model="form.username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus maxlength="30" :aria-invalid="!!form.errors.username" :aria-describedby="describedBy"></template></Field>
                <Field id="password" label="Password" :error="form.errors.password"><template #default="{ describedBy }"><input id="password" v-model="form.password" type="password" autocomplete="current-password" required :aria-invalid="!!form.errors.password" :aria-describedby="describedBy"></template></Field>
                <button class="admin-primary w-full" :disabled="form.processing">{{ form.processing ? 'Checking…' : 'Log in' }}</button>
            </form>
            <p class="mt-6 text-xs text-chocolate/60">Don't have access yet? Contact an Orlena admin.</p>
        </section>
    </main>
</template>

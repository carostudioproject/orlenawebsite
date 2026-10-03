<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import Field from '../../Components/Field.vue';
import type { AdminProps } from '../../Types/admin';
defineOptions({ layout: AdminLayout });
const props = defineProps<{ profile: { name: string; username: string; email: string | null; role: string } }>();
const page = usePage<AdminProps>();
const initials = computed(() => props.profile.name.split(/\s+/).map(word => word[0]).join('').slice(0, 2).toUpperCase());

const details = useForm({ name: props.profile.name, username: props.profile.username, email: props.profile.email ?? '' });
watch(() => props.profile, profile => { details.defaults({ name: profile.name, username: profile.username, email: profile.email ?? '' }); details.reset(); });
const password = useForm({ current_password: '', password: '', password_confirmation: '' });
function saveDetails() { details.put('/admin/profile', { preserveScroll: true }); }
function savePassword() { password.put('/admin/profile/password', { preserveScroll: true, onSuccess: () => password.reset(), onError: () => password.reset('current_password') }); }
</script>
<template>
    <Head title="My profile · Orlena" />
    <p class="admin-eyebrow">Settings</p><h1 class="text-3xl font-bold">My profile</h1>
    <p class="admin-muted mt-2 text-sm">Change your own login details. Role and account status can only be changed by an Admin.</p>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <section class="admin-card space-y-5" aria-labelledby="profile-title">
            <div class="flex items-center gap-4">
                <span class="flex size-14 items-center justify-center rounded-full bg-almond text-lg font-bold" aria-hidden="true">{{ initials }}</span>
                <div><h2 id="profile-title" class="m-0 text-xl">{{ profile.name }}</h2><p class="admin-muted m-0 text-sm">@{{ profile.username }} · {{ page.props.auth.user.role_label }}</p></div>
            </div>
            <form class="space-y-5" @submit.prevent="saveDetails">
                <Field id="name" label="Name" :error="details.errors.name"><template #default="{ describedBy }"><input id="name" v-model="details.name" autocomplete="name" required maxlength="160" :aria-describedby="describedBy" :aria-invalid="!!details.errors.name"></template></Field>
                <Field id="username" label="Username" hint="Used to log in. 3–30 characters: lowercase letters, numbers, dots, dashes or underscores." :error="details.errors.username"><template #default="{ describedBy }"><input id="username" v-model="details.username" autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="30" :aria-describedby="describedBy" :aria-invalid="!!details.errors.username" @input="details.username = details.username.toLowerCase()"></template></Field>
                <Field id="email" label="Email (optional)" hint="For internal contact; not used to log in." :error="details.errors.email"><template #default="{ describedBy }"><input id="email" v-model="details.email" type="email" autocomplete="email" maxlength="254" :aria-describedby="describedBy" :aria-invalid="!!details.errors.email"></template></Field>
                <button class="admin-primary" :disabled="details.processing || !details.isDirty"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ details.processing ? 'Saving…' : 'Save profile' }}</button>
            </form>
        </section>

        <section class="admin-card space-y-5 self-start" aria-labelledby="password-title">
            <div><h2 id="password-title" class="m-0 text-xl">Change password</h2><p class="admin-muted mt-1 text-sm">After changing it, sessions on other devices are logged out automatically.</p></div>
            <form class="space-y-5" @submit.prevent="savePassword">
                <Field id="current_password" label="Current password" :error="password.errors.current_password"><template #default="{ describedBy }"><input id="current_password" v-model="password.current_password" type="password" autocomplete="current-password" required :aria-describedby="describedBy" :aria-invalid="!!password.errors.current_password"></template></Field>
                <Field id="new_password" label="New password" hint="At least 12 characters, with letters and numbers." :error="password.errors.password"><template #default="{ describedBy }"><input id="new_password" v-model="password.password" type="password" autocomplete="new-password" required minlength="12" maxlength="128" :aria-describedby="describedBy" :aria-invalid="!!password.errors.password"></template></Field>
                <Field id="password_confirmation" label="Repeat new password" :error="password.errors.password_confirmation"><template #default="{ describedBy }"><input id="password_confirmation" v-model="password.password_confirmation" type="password" autocomplete="new-password" required maxlength="128" :aria-describedby="describedBy"></template></Field>
                <button class="admin-primary" :disabled="password.processing"><i class="fa-solid fa-key" aria-hidden="true"></i>{{ password.processing ? 'Saving…' : 'Change password' }}</button>
            </form>
        </section>
    </div>
</template>

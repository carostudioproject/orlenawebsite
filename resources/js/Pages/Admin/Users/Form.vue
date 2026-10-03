<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Field from '../../../Components/Field.vue';
import { roleOptions, type StaffRole, type StaffUser } from '../../../Types/admin';
defineOptions({ layout: AdminLayout });
const props = defineProps<{ record: StaffUser | null }>();
const form = useForm({ name: props.record?.name ?? '', username: props.record?.username ?? '', email: props.record?.email ?? '', role: (props.record?.role ?? 'staff') as StaffRole, is_active: props.record?.is_active ?? true, password: '', password_confirmation: '' });
function submit() {
    const options = { onFinish: () => form.reset('password', 'password_confirmation') };
    if (props.record) form.put(`/admin/users/${props.record.id}`, options); else form.post('/admin/users', options);
}
</script>
<template><Head title="Manage account · Orlena" /><Link href="/admin/users" class="admin-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to team accounts</Link><h1 class="my-5 text-3xl font-bold">{{ record ? 'Edit account' : 'Add account' }}</h1><form class="admin-card max-w-2xl space-y-5" @submit.prevent="submit">
    <Field id="name" label="Name" :error="form.errors.name"><template #default="{ describedBy }"><input id="name" v-model="form.name" autocomplete="name" required :aria-describedby="describedBy" :aria-invalid="!!form.errors.name"></template></Field>
    <Field id="username" label="Username (for login)" hint="3–30 characters: lowercase letters, numbers, dots, dashes or underscores." :error="form.errors.username"><template #default="{ describedBy }"><input id="username" v-model="form.username" autocomplete="off" autocapitalize="none" spellcheck="false" required maxlength="30" :aria-describedby="describedBy" :aria-invalid="!!form.errors.username" @input="form.username = form.username.toLowerCase()"></template></Field>
    <Field id="email" label="Email (optional)" :error="form.errors.email"><template #default="{ describedBy }"><input id="email" v-model="form.email" type="email" autocomplete="off" :aria-describedby="describedBy" :aria-invalid="!!form.errors.email"></template></Field>
    <Field id="role" label="Access role" :error="form.errors.role"><template #default="{ describedBy }"><select id="role" v-model="form.role" :aria-describedby="describedBy" :aria-invalid="!!form.errors.role"><option v-for="option in roleOptions" :key="option.value" :value="option.value">{{ option.label }} — {{ option.description }}</option></select></template></Field>
    <Field id="password" :label="record ? 'New password (leave empty to keep it)' : 'Password'" :error="form.errors.password"><template #default="{ describedBy }"><input id="password" v-model="form.password" type="password" autocomplete="new-password" minlength="12" maxlength="128" :required="!record" :aria-describedby="describedBy" :aria-invalid="!!form.errors.password"></template></Field>
    <p class="text-xs text-chocolate/60">At least 12 characters, with letters and numbers.</p>
    <Field id="password_confirmation" label="Repeat password" :error="form.errors.password_confirmation"><template #default="{ describedBy }"><input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" :required="!!form.password" :aria-describedby="describedBy"></template></Field>
    <label class="flex items-center gap-3 text-sm"><input v-model="form.is_active" type="checkbox">Account active</label>
    <div class="flex gap-3"><button class="admin-primary" :disabled="form.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ form.processing ? 'Saving…' : 'Save account' }}</button><Link href="/admin/users" class="admin-secondary"><i class="fa-solid fa-xmark" aria-hidden="true"></i>Cancel</Link></div>
</form></template>

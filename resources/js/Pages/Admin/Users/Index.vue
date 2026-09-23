<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Pagination from '../../../Components/Admin/Pagination.vue';
import SearchInput from '../../../Components/Admin/SearchInput.vue';
import { useLiveFilters } from '../../../Composables/useLiveFilters';
import { roleOptions, type Paginated, type StaffUser } from '../../../Types/admin';
const roleLabel = (role: string) => roleOptions.find(option => option.value === role)?.label ?? role;
defineOptions({ layout: AdminLayout });
const props = defineProps<{ users: Paginated<StaffUser>; filters: { search?: string; role?: string } }>();
const { filters: live, loading, active, apply, reset } = useLiveFilters(() => '/admin/users', { search: props.filters.search ?? '', role: props.filters.role ?? '' });
</script>
<template>
    <Head title="Team accounts · Orlena" />
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4"><h1 class="text-3xl font-bold">Team accounts</h1><Link href="/admin/users/create" class="admin-primary"><i class="fa-solid fa-user-plus" aria-hidden="true"></i>Add account</Link></div>
    <form class="mb-6 flex flex-wrap items-end gap-3" @submit.prevent="apply">
        <SearchInput id="search" v-model="live.search" label="Search by name, username or email" :loading="loading" />
        <div><label for="role" class="mb-2 block text-sm">Role</label><select id="role" v-model="live.role"><option value="">All</option><option v-for="option in roleOptions" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
        <button v-if="active" type="button" class="admin-secondary" @click="reset"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset</button>
    </form>
    <div class="admin-card overflow-x-auto">
        <table v-if="users.data.length" class="w-full text-left text-sm"><thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th class="text-right">Actions</th></tr></thead><tbody><tr v-for="user in users.data" :key="user.id"><td>{{ user.name }}</td><td class="font-bold">@{{ user.username }}</td><td>{{ user.email ?? '—' }}</td><td>{{ roleLabel(user.role) }}</td><td>{{ user.is_active ? 'Active' : 'Inactive' }}</td><td><div class="admin-actions"><Link :href="`/admin/users/${user.id}/edit`" class="admin-action" :aria-label="`Edit account ${user.name}`"><i class="fa-solid fa-pen" aria-hidden="true"></i>Edit</Link></div></td></tr></tbody></table>
        <p v-else class="m-0 py-8 text-center text-sm">No matching accounts.</p>
    </div>
    <Pagination :links="users.links" />
</template>

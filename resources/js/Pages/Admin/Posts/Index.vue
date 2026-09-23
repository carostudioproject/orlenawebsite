<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Pagination from '../../../Components/Admin/Pagination.vue';
import SearchInput from '../../../Components/Admin/SearchInput.vue';
import { useLiveFilters } from '../../../Composables/useLiveFilters';
import type { Paginated } from '../../../Types/admin';
import { witaTime } from '../../../Support/orderStatus';
defineOptions({ layout: AdminLayout });
interface PostRow { id: number; slug: string; title: string; category: string | null; is_published: boolean; updated_at: string; editor: { name: string } | null }
const props = defineProps<{ posts: Paginated<PostRow>; filters: { search: string | null } }>();
const { filters: live, loading, active, apply, reset } = useLiveFilters(() => '/admin/posts', { search: props.filters.search ?? '' });
</script>
<template>
    <Head title="Blog · Orlena" />
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4"><div><p class="admin-eyebrow">Website</p><h1 class="text-3xl font-bold">Blog / Journal</h1><p class="admin-muted mt-2 text-sm">Newest articles are listed first. The homepage shows the 5 newest published articles.</p></div><Link href="/admin/posts/create" class="admin-primary"><i class="fa-solid fa-plus" aria-hidden="true"></i>Write an article</Link></div>
    <form class="mb-6 flex flex-wrap items-end gap-3" @submit.prevent="apply"><SearchInput id="search" v-model="live.search" label="Search by title" :loading="loading" /><button v-if="active" type="button" class="admin-secondary" @click="reset"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset</button></form>
    <div class="admin-card overflow-x-auto">
        <table v-if="posts.data.length" class="w-full text-left text-sm"><thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Last edited</th><th class="text-right">Actions</th></tr></thead><tbody>
            <tr v-for="post in posts.data" :key="post.id"><td><Link :href="`/admin/posts/${post.id}/edit`" class="font-bold underline">{{ post.title }}</Link><span class="admin-muted block text-xs">/blog/{{ post.slug }}</span></td><td>{{ post.category ?? '—' }}</td><td><span class="rounded-full px-3 py-1 text-xs font-bold" :class="post.is_published ? 'bg-matcha/15' : 'bg-almond'">{{ post.is_published ? 'Published' : 'Draft' }}</span></td><td class="whitespace-nowrap">{{ witaTime(post.updated_at) }}<span class="admin-muted block text-xs">{{ post.editor?.name ?? 'Original copy' }}</span></td><td><div class="admin-actions"><a v-if="post.is_published" :href="`/blog/${post.slug}`" target="_blank" rel="noopener" class="admin-action-icon" :aria-label="`View ${post.title} on the website`" title="View on website"><i class="fa-solid fa-eye" aria-hidden="true"></i></a><Link :href="`/admin/posts/${post.id}/edit`" class="admin-action" :aria-label="`Edit ${post.title}`"><i class="fa-solid fa-pen" aria-hidden="true"></i>Edit</Link></div></td></tr>
        </tbody></table>
        <p v-else class="m-0 py-8 text-center text-sm">No matching articles.</p>
    </div>
    <Pagination :links="posts.links" />
</template>

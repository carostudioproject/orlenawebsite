<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
defineOptions({ layout: AdminLayout });
interface Row { id: number; name: string; address?: string; image: string | null; is_active: boolean; show_on_website: boolean }
const props = defineProps<{ section: 'outlets' | 'bakedGoods'; items: Row[] }>();
// Details are edited in the catalog menus; this page chooses which records appear on the website and in what order.
const copy = {
    outlets: { title: 'Outlets on the website', noun: 'outlets', page: 'Home & About', manage: '/admin/outlets', manageLabel: 'Manage outlets', icon: 'fa-store', preview: '/#outlet', hint: 'Choose which outlets show on the homepage and About page, then set their order. Name, address, photo and status are edited under Outlets.', inactive: 'Inactive outlets stay hidden until activated under Outlets.', created: 'New outlets are created under Manage outlets.' },
    bakedGoods: { title: 'Categories in Baked Goods', noun: 'categories', page: 'Home', manage: '/admin/categories', manageLabel: 'Manage categories', icon: 'fa-tags', preview: '/#baked-goods', hint: 'Choose which categories show in the homepage Baked Goods section, then set their order. Name, photo and status are edited under Categories.', inactive: 'Inactive categories stay hidden until activated under Categories.', created: 'New categories are created under Manage categories.' },
};
const text = computed(() => copy[props.section]);
const shownFrom = (items: Row[]) => items.filter(item => item.show_on_website);
const shown = ref(shownFrom(props.items));
watch(() => props.items, items => { shown.value = shownFrom(items); });
const available = computed(() => props.items.filter(item => !shown.value.some(row => row.id === item.id)).sort((a, b) => a.name.localeCompare(b.name)));
const form = useForm({ order: [] as number[] });
function move(index: number, step: number) { const [row] = shown.value.splice(index, 1); shown.value.splice(index + step, 0, row); }
function add(row: Row) { shown.value.push(row); }
function hide(index: number) { shown.value.splice(index, 1); }
function save() { form.transform(() => ({ order: shown.value.map(row => row.id) })).post(`/admin/content/${props.section}`, { preserveScroll: true }); }
const original = computed(() => shownFrom(props.items).map(row => row.id).join(','));
const changed = computed(() => shown.value.map(row => row.id).join(',') !== original.value);
</script>
<template>
    <Head :title="`${text.title} · Orlena`" />
    <Link href="/admin/content" class="admin-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to content</Link>
    <div class="my-5 flex flex-wrap items-end justify-between gap-4"><div><p class="admin-eyebrow">Website · {{ text.page }}</p><h1 class="text-3xl font-bold">{{ text.title }}</h1><p class="admin-muted mt-2 max-w-2xl text-sm">{{ text.hint }}</p></div><div class="flex gap-2"><a :href="text.preview" target="_blank" rel="noopener" class="admin-secondary"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>View on website</a><Link :href="text.manage" class="admin-secondary"><i class="fa-solid" :class="text.icon" aria-hidden="true"></i>{{ text.manageLabel }}</Link></div></div>
    <p v-if="form.hasErrors" role="alert" class="admin-alert admin-alert-error mb-4">Changes could not be saved. Reload the page and try again.</p>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
        <section class="admin-card" aria-labelledby="shown-title">
            <div class="mb-3 flex items-center justify-between gap-3"><h2 id="shown-title" class="text-lg">Shown on the website</h2><span class="rounded-full bg-almond px-3 py-0.5 text-sm font-bold">{{ shown.length }}</span></div>
            <ol class="divide-y divide-chocolate/10">
                <li v-for="(row, index) in shown" :key="row.id" class="flex items-center gap-3 py-3">
                    <span class="w-6 text-center text-sm font-bold tabular-nums">{{ index + 1 }}</span>
                    <span class="flex h-12 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-cream"><img v-if="row.image" :src="row.image" alt="" class="size-full object-cover"><i v-else class="fa-solid fa-image text-chocolate/30" aria-hidden="true"></i></span>
                    <span class="min-w-0 flex-1"><span class="block truncate font-bold">{{ row.name }}</span><span v-if="!row.is_active" class="admin-error-text block text-xs">Inactive — not shown yet</span><span v-else-if="!row.image" class="admin-muted block text-xs">No photo yet — shows the logo</span><span v-else-if="row.address" class="admin-muted block truncate text-xs">{{ row.address }}</span></span>
                    <span class="flex shrink-0 gap-1"><button type="button" class="admin-action-icon" :disabled="index === 0" :aria-label="`Move ${row.name} up`" title="Move up" @click="move(index, -1)"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button><button type="button" class="admin-action-icon" :disabled="index === shown.length - 1" :aria-label="`Move ${row.name} down`" title="Move down" @click="move(index, 1)"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button><button type="button" class="admin-action-icon admin-danger" :aria-label="`Hide ${row.name} from the website`" title="Hide" @click="hide(index)"><i class="fa-solid fa-eye-slash" aria-hidden="true"></i></button></span>
                </li>
            </ol>
            <p v-if="!shown.length" class="admin-muted py-6 text-center text-sm">No {{ text.noun }} are shown yet. Add them from the list alongside.</p>
        </section>

        <section class="admin-card self-start" aria-labelledby="available-title">
            <h2 id="available-title" class="mb-1 text-lg">Add from existing</h2>
            <p class="admin-muted mb-3 text-xs">{{ text.inactive }} {{ text.created }}</p>
            <ul class="divide-y divide-chocolate/10">
                <li v-for="row in available" :key="row.id" class="flex items-center gap-3 py-2.5">
                    <span class="flex h-10 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-cream"><img v-if="row.image" :src="row.image" alt="" class="size-full object-cover"><i v-else class="fa-solid fa-image text-xs text-chocolate/30" aria-hidden="true"></i></span>
                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold">{{ row.name }}</span><span v-if="!row.is_active" class="admin-muted text-xs">Inactive</span></span>
                    <button type="button" class="admin-action shrink-0" :aria-label="`Add ${row.name} to the website`" @click="add(row)"><i class="fa-solid fa-plus" aria-hidden="true"></i>Add</button>
                </li>
            </ul>
            <p v-if="!available.length" class="admin-muted py-4 text-center text-sm">All {{ text.noun }} are already shown.</p>
        </section>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3"><button type="button" class="admin-primary" :disabled="form.processing || !changed" @click="save"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ form.processing ? 'Saving…' : 'Save website display' }}</button><span v-if="changed" class="admin-muted text-sm">You have unsaved changes.</span></div>
</template>

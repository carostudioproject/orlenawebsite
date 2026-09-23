<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { contentSections } from '../../../Support/contentSections';
import { witaTime } from '../../../Support/orderStatus';
defineOptions({ layout: AdminLayout });
interface SectionState { key: string; customized: boolean; updated_at: string | null; editor: string | null }
const props = defineProps<{ sections: SectionState[]; outlets: { total: number; shown: number }; categories: { total: number; shown: number }; posts: { total: number; published: number } }>();
const icons: Record<string, string> = { social: 'fa-share-nodes', hero: 'fa-images', home: 'fa-heading', collaborations: 'fa-handshake', about: 'fa-book-open' };
// Cards follow the order sections appear on each page; Baked Goods and outlet order sit after the homepage text like on the site.
const groups = computed(() => (['Global', 'Home', 'About'] as const).map(page => ({
    page, sections: props.sections.filter(section => contentSections[section.key]?.page === page),
})));
</script>
<template>
    <Head title="Website content · Orlena" />
    <p class="admin-eyebrow">Website</p><h1 class="text-3xl font-bold">Website content</h1>
    <p class="admin-muted mt-2 max-w-2xl">Changes show on the website right away. The approved original copy can always be restored from the edit page.</p>
    <section v-for="group in groups" :key="group.page" class="mt-8" :aria-labelledby="`group-${group.page}`">
        <h2 :id="`group-${group.page}`" class="mb-4 text-xl">{{ { Global: 'All pages', Home: 'Homepage', About: 'About page' }[group.page] }}</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <template v-for="section in group.sections" :key="section.key">
                <article class="admin-card flex flex-col">
                    <div class="mb-3 flex items-start justify-between gap-3"><span class="admin-kpi-icon bg-almond"><i class="fa-solid" :class="icons[section.key]" aria-hidden="true"></i></span><span class="rounded-full px-3 py-1 text-xs font-bold" :class="section.customized ? 'bg-rose/40' : 'bg-cream'">{{ section.customized ? 'Edited' : 'Original copy' }}</span></div>
                    <h3 class="mb-1 text-lg font-bold">{{ contentSections[section.key].title }}</h3>
                    <p class="admin-muted flex-1 text-sm">{{ contentSections[section.key].description }}</p>
                    <p v-if="section.updated_at" class="admin-muted text-xs">Edited {{ witaTime(section.updated_at) }} by {{ section.editor ?? 'unknown account' }}</p>
                    <Link :href="`/admin/content/${section.key}`" class="admin-primary mt-4 self-start"><i class="fa-solid fa-pen" aria-hidden="true"></i>Edit</Link>
                </article>
                <article v-if="section.key === 'home'" class="admin-card flex flex-col">
                    <div class="mb-3"><span class="admin-kpi-icon bg-almond"><i class="fa-solid fa-cookie-bite" aria-hidden="true"></i></span></div>
                    <h3 class="mb-1 text-lg font-bold">Categories in Baked Goods</h3>
                    <p class="admin-muted flex-1 text-sm">{{ categories.shown }} of {{ categories.total }} categories show in Baked Goods. Choose which ones show and their order; names and photos are under Categories.</p>
                    <Link href="/admin/content/bakedGoods" class="admin-primary mt-4 self-start"><i class="fa-solid fa-list-check" aria-hidden="true"></i>Manage display</Link>
                </article>
                <article v-if="section.key === 'home'" class="admin-card flex flex-col">
                    <div class="mb-3"><span class="admin-kpi-icon bg-almond"><i class="fa-solid fa-store" aria-hidden="true"></i></span></div>
                    <h3 class="mb-1 text-lg font-bold">Outlets on the website</h3>
                    <p class="admin-muted flex-1 text-sm">{{ outlets.shown }} of {{ outlets.total }} outlets show on Home and About. Choose which ones show and their order; outlet details are under Outlets.</p>
                    <Link href="/admin/content/outlets" class="admin-primary mt-4 self-start"><i class="fa-solid fa-list-check" aria-hidden="true"></i>Manage display</Link>
                </article>
            </template>
        </div>
    </section>
    <section class="mt-8" aria-labelledby="group-blog">
        <h2 id="group-blog" class="mb-4 text-xl">Blog / Journal</h2>
        <article class="admin-card flex flex-wrap items-center justify-between gap-4">
            <p class="admin-muted m-0 text-sm">{{ posts.published }} of {{ posts.total }} articles are published. The 5 newest show on the homepage; the Blog page shows all of them.</p>
            <div class="flex gap-2"><Link href="/admin/posts" class="admin-primary"><i class="fa-solid fa-newspaper" aria-hidden="true"></i>Manage articles</Link><Link href="/admin/posts/create" class="admin-secondary"><i class="fa-solid fa-plus" aria-hidden="true"></i>Write an article</Link></div>
        </article>
    </section>
</template>

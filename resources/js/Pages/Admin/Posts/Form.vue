<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Field from '../../../Components/Admin/Field.vue';
import ImageInput from '../../../Components/Admin/ImageInput.vue';
import type { ContentBlock } from '../../../Types/content';
defineOptions({ layout: AdminLayout });
interface PostRecord { id: number; slug: string; category: string; title: string; excerpt: string; image: string; content: ContentBlock[]; is_published: boolean }
const props = defineProps<{ record: PostRecord | null }>();
const initial = () => ({
    slug: props.record?.slug ?? '', category: props.record?.category ?? '' as string | null, title: props.record?.title ?? '', excerpt: props.record?.excerpt ?? '',
    image: props.record?.image ?? null as string | null, upload: null as File | null,
    content: props.record?.content.map(block => ({ ...block })) ?? [{ type: 'paragraph', text: '' }] as ContentBlock[],
    is_published: props.record?.is_published ?? false,
});
const form = useForm(initial());
const errors = computed(() => form.errors as Record<string, string>);
const fileKey = ref(0);
watch(() => props.record, () => { form.defaults(initial()); form.reset(); fileKey.value++; });

// Suggest a slug from the title until the editor types one; published slugs are left alone to keep links working.
const slugTouched = ref(!!props.record);
const slugify = (value: string) => value.toLowerCase().normalize('NFKD').replace(/\p{M}/gu, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 160);
watch(() => form.title, title => { if (!slugTouched.value) form.slug = slugify(title); });
const slugChanged = computed(() => props.record?.is_published && form.slug !== props.record.slug);

function addBlock(type: ContentBlock['type']) { form.content.push({ type, text: '' }); }
function move(index: number, step: number) { const [block] = form.content.splice(index, 1); form.content.splice(index + step, 0, block); }
function save() {
    const options = { forceFormData: true, preserveScroll: true };
    if (props.record) form.transform(data => ({ ...data, _method: 'put' })).post(`/admin/posts/${props.record.id}`, options);
    else form.post('/admin/posts', options);
}
</script>
<template>
    <Head :title="`${record ? 'Edit article' : 'New article'} · Orlena`" />
    <Link href="/admin/posts" class="admin-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to blog</Link>
    <div class="my-5 flex flex-wrap items-end justify-between gap-4"><div><p class="admin-eyebrow">Blog</p><h1 class="text-3xl font-bold">{{ record ? 'Edit article' : 'New article' }}</h1></div><a v-if="record?.is_published" :href="`/blog/${record.slug}`" target="_blank" rel="noopener" class="admin-secondary"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>View on website</a></div>
    <form class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_320px]" @submit.prevent="save">
        <div class="space-y-6">
            <div v-if="form.hasErrors" role="alert" class="admin-alert admin-alert-error">Please check the highlighted fields.</div>
            <section class="admin-card space-y-5">
                <Field id="title" label="Title" :error="errors.title"><template #default="{ describedBy }"><input id="title" v-model="form.title" required maxlength="200" :aria-describedby="describedBy"></template></Field>
                <Field id="excerpt" label="Excerpt" hint="Shown on blog cards and as the SEO description." :error="errors.excerpt"><template #default="{ describedBy }"><textarea id="excerpt" v-model="form.excerpt" required maxlength="500" rows="3" :aria-describedby="describedBy"></textarea></template></Field>
            </section>
            <section class="admin-card space-y-4" aria-labelledby="content-heading">
                <h2 id="content-heading" class="text-xl">Article body</h2>
                <p v-if="errors.content" class="admin-error-text text-sm" role="alert">{{ errors.content }}</p>
                <div v-for="(block, index) in form.content" :key="index" class="rounded-2xl border border-chocolate/10 p-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2"><label :for="`block-${index}-type`" class="sr-only">Block {{ index + 1 }} type</label><select :id="`block-${index}-type`" v-model="block.type" class="max-w-40"><option value="paragraph">Paragraph</option><option value="heading">Subheading</option></select><div class="flex gap-2"><button type="button" class="admin-action-icon" :disabled="index === 0" :aria-label="`Move block ${index + 1} up`" title="Move up" @click="move(index, -1)"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button><button type="button" class="admin-action-icon" :disabled="index === form.content.length - 1" :aria-label="`Move block ${index + 1} down`" title="Move down" @click="move(index, 1)"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button><button type="button" class="admin-action-icon admin-danger" :disabled="form.content.length === 1" :aria-label="`Remove block ${index + 1}`" title="Remove block" @click="form.content.splice(index, 1)"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button></div></div>
                    <label :for="`block-${index}-text`" class="sr-only">Block {{ index + 1 }} text</label>
                    <input v-if="block.type === 'heading'" :id="`block-${index}-text`" v-model="block.text" required maxlength="5000" class="font-bold">
                    <textarea v-else :id="`block-${index}-text`" v-model="block.text" required maxlength="5000" rows="4"></textarea>
                    <p v-if="errors[`content.${index}.text`]" class="admin-error-text mt-2 text-sm" role="alert">{{ errors[`content.${index}.text`] }}</p>
                </div>
                <div class="flex flex-wrap gap-2"><button type="button" class="admin-secondary" :disabled="form.content.length >= 100" @click="addBlock('paragraph')"><i class="fa-solid fa-plus" aria-hidden="true"></i>Paragraph</button><button type="button" class="admin-secondary" :disabled="form.content.length >= 100" @click="addBlock('heading')"><i class="fa-solid fa-heading" aria-hidden="true"></i>Subheading</button></div>
            </section>
        </div>
        <aside class="space-y-6 self-start xl:sticky xl:top-24">
            <section class="admin-card space-y-5">
                <label class="flex items-center gap-3 text-sm font-bold"><input v-model="form.is_published" type="checkbox"> Publish on the website</label>
                <p class="admin-muted text-xs">Drafts do not appear on the website, homepage or sitemap.</p>
                <button class="admin-primary w-full" :disabled="form.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ form.processing ? 'Saving…' : 'Save article' }}</button>
            </section>
            <section class="admin-card space-y-5">
                <Field id="category" label="Category (optional)" hint="E.g. Orlena Cafe Jimbaran." :error="errors.category"><template #default="{ describedBy }"><input id="category" v-model="form.category" maxlength="120" :aria-describedby="describedBy"></template></Field>
                <Field id="slug" label="Slug (article URL)" :hint="`/blog/${form.slug || 'sample-article'}`" :error="errors.slug"><template #default="{ describedBy }"><input id="slug" v-model="form.slug" required maxlength="160" :aria-describedby="describedBy" @input="slugTouched = true"></template></Field>
                <p v-if="slugChanged" class="admin-alert admin-alert-error text-xs">Changing the slug of a published article breaks its old link.</p>
                <ImageInput :key="fileKey" id="cover" label="Cover image" :current="form.image" :error="errors.upload ?? errors.image" @select="file => (form.upload = file)" />
            </section>
        </aside>
    </form>
</template>

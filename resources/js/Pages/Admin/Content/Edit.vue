<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Field from '../../../Components/Admin/Field.vue';
import ImageInput from '../../../Components/Admin/ImageInput.vue';
import { contentSections } from '../../../Support/contentSections';
import { confirmDialog } from '../../../Support/dialog';
defineOptions({ layout: AdminLayout });
type Item = Record<string, string | null>;
type Copy = Record<string, string | string[]>;
const props = defineProps<{ section: string; value: Copy | Record<string, string>[]; customized: boolean }>();
const config = computed(() => contentSections[props.section]);

// Object sections (homepage text, About) edit one record; hero, Baked Goods and collaborations are ordered lists.
const toCopy = (): Copy => (config.value.list ? {} : JSON.parse(JSON.stringify(props.value)));
const toItems = (): Item[] => (config.value.list ? (props.value as Record<string, string>[]).map(item => ({ ...item })) : []);
const copyForm = useForm({ value: toCopy() });
const listForm = useForm<{ items: Item[] }>({ items: toItems() });
// New image files sit beside the items so text fields stay plain strings.
const uploads = ref<(File | null)[]>(listForm.items.map(() => null));
// After a save or reset the server sends fresh values; rebuild the form so uploaded files are not sent twice.
const version = ref(0);
watch(() => props.value, () => {
    copyForm.value = toCopy();
    listForm.items = toItems();
    uploads.value = listForm.items.map(() => null);
    version.value++;
});
const form = computed(() => (config.value.list ? listForm : copyForm));
const errors = computed(() => form.value.errors as Record<string, string>);
const paragraphs = (key: string) => copyForm.value[key] as string[];

function blank(): Item { return Object.fromEntries([...config.value.fields.map(field => [field.key, '']), ['image', null]]); }
function add() { listForm.items.push(blank()); uploads.value.push(null); }
function move<T>(list: T[], index: number, step: number) { const [entry] = list.splice(index, 1); list.splice(index + step, 0, entry); }
function moveItem(index: number, step: number) { move(listForm.items, index, step); move(uploads.value, index, step); }
async function remove(index: number) {
    const ok = await confirmDialog({ title: `Remove ${config.value.itemLabel?.toLowerCase()} ${index + 1}?`, tone: 'danger', confirmLabel: 'Remove', message: 'The item is removed from this list. The website changes only after you save.' });
    if (ok) { listForm.items.splice(index, 1); uploads.value.splice(index, 1); }
}
function save() {
    const url = `/admin/content/${props.section}`;
    if (config.value.list) listForm.transform(data => ({ items: data.items.map((item, index) => ({ ...item, upload: uploads.value[index] })) })).post(url, { forceFormData: true, preserveScroll: true });
    else copyForm.post(url, { preserveScroll: true });
}
async function reset() {
    const ok = await confirmDialog({ title: 'Restore the original copy?', tone: 'danger', icon: 'fa-rotate-left', confirmLabel: 'Restore', message: 'This section goes back to the approved original copy and shows on the website right away. Saved changes will be lost.' });
    if (ok) router.post(`/admin/content/${props.section}/reset`, {}, { preserveScroll: true });
}
</script>
<template>
    <Head :title="`${config.title} · Orlena`" />
    <Link href="/admin/content" class="admin-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to content</Link>
    <div class="my-5 flex flex-wrap items-end justify-between gap-4"><div><p class="admin-eyebrow">Website · {{ { Global: 'All pages', Home: 'Homepage', About: 'About' }[config.page] }}</p><h1 class="text-3xl font-bold">{{ config.title }}</h1><p class="admin-muted mt-2 max-w-2xl text-sm">{{ config.description }}</p></div><a :href="config.preview" target="_blank" rel="noopener" class="admin-secondary"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>View on website</a></div>
    <p class="admin-alert admin-alert-info mb-6">Changes show on the website as soon as they are saved. Make sure the owner approved the copy.</p>
    <form class="space-y-6" @submit.prevent="save">
        <div v-if="form.hasErrors" role="alert" class="admin-alert admin-alert-error">Please check the highlighted fields.</div>

        <section v-if="!config.list" :key="version" class="admin-card space-y-5">
            <template v-for="field in config.fields" :key="field.key">
                <fieldset v-if="field.paragraphs" class="space-y-3">
                    <legend class="text-sm font-bold">{{ field.label }}</legend>
                    <p v-if="errors[`value.${field.key}`]" class="admin-error-text text-sm" role="alert">{{ errors[`value.${field.key}`] }}</p>
                    <div v-for="(paragraph, index) in paragraphs(field.key)" :key="index" class="flex gap-2">
                        <div class="min-w-0 flex-1"><label :for="`${field.key}-${index}`" class="sr-only">Paragraph {{ index + 1 }}</label><textarea :id="`${field.key}-${index}`" v-model="paragraphs(field.key)[index]" required :maxlength="field.max" rows="3"></textarea><p v-if="errors[`value.${field.key}.${index}`]" class="admin-error-text mt-1 text-sm" role="alert">{{ errors[`value.${field.key}.${index}`] }}</p></div>
                        <div class="flex flex-col gap-1"><button type="button" class="admin-action-icon" :disabled="index === 0" :aria-label="`Move paragraph ${index + 1} up`" title="Move up" @click="move(paragraphs(field.key), index, -1)"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button><button type="button" class="admin-action-icon" :disabled="index === paragraphs(field.key).length - 1" :aria-label="`Move paragraph ${index + 1} down`" title="Move down" @click="move(paragraphs(field.key), index, 1)"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button><button type="button" class="admin-action-icon admin-danger" :disabled="paragraphs(field.key).length === 1" :aria-label="`Remove paragraph ${index + 1}`" title="Remove" @click="paragraphs(field.key).splice(index, 1)"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button></div>
                    </div>
                    <button type="button" class="admin-secondary" :disabled="paragraphs(field.key).length >= 10" @click="paragraphs(field.key).push('')"><i class="fa-solid fa-plus" aria-hidden="true"></i>Add paragraph</button>
                </fieldset>
                <Field v-else :id="field.key" :label="field.label" :hint="field.hint" :error="errors[`value.${field.key}`]"><template #default="{ describedBy }"><textarea v-if="field.multiline" :id="field.key" v-model="copyForm.value[field.key]" required :maxlength="field.max" rows="3" :aria-describedby="describedBy" :aria-invalid="!!errors[`value.${field.key}`]"></textarea><input v-else :id="field.key" v-model="copyForm.value[field.key]" :type="field.type ?? 'text'" :required="!field.optional" :placeholder="field.placeholder" :maxlength="field.max" :aria-describedby="describedBy" :aria-invalid="!!errors[`value.${field.key}`]"></template></Field>
            </template>
        </section>

        <template v-else>
            <p v-if="errors.items" class="admin-error-text text-sm" role="alert">{{ errors.items }}</p>
            <section v-for="(item, index) in listForm.items" :key="`${version}-${index}`" class="admin-card space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg">{{ config.itemLabel }} {{ index + 1 }}</h2><div class="flex gap-2"><button type="button" class="admin-action-icon" :disabled="index === 0" :aria-label="`Move ${config.itemLabel} ${index + 1} up`" title="Move up" @click="moveItem(index, -1)"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button><button type="button" class="admin-action-icon" :disabled="index === listForm.items.length - 1" :aria-label="`Move ${config.itemLabel} ${index + 1} down`" title="Move down" @click="moveItem(index, 1)"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></button><button type="button" class="admin-action admin-danger" :disabled="listForm.items.length === 1" @click="remove(index)"><i class="fa-solid fa-trash-can" aria-hidden="true"></i>Remove</button></div></div>
                <ImageInput :id="`item-${index}-image`" :label="config.imageLabel ?? 'Image'" :current="item.image" :error="errors[`items.${index}.upload`] ?? errors[`items.${index}.image`]" @select="file => (uploads[index] = file)" />
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <Field v-for="field in config.fields" :id="`item-${index}-${field.key}`" :key="field.key" :label="field.label" :hint="field.hint" :error="errors[`items.${index}.${field.key}`]"><template #default="{ describedBy }"><textarea v-if="field.multiline" :id="`item-${index}-${field.key}`" v-model="item[field.key]" required :maxlength="field.max" rows="2" :aria-describedby="describedBy"></textarea><input v-else :id="`item-${index}-${field.key}`" v-model="item[field.key]" :type="field.type ?? 'text'" required :maxlength="field.max" :aria-describedby="describedBy"></template></Field>
                </div>
            </section>
            <button v-if="listForm.items.length < (config.maxItems ?? 20)" type="button" class="admin-secondary" @click="add"><i class="fa-solid fa-plus" aria-hidden="true"></i>Add {{ config.itemLabel?.toLowerCase() }}</button>
        </template>

        <div class="flex flex-wrap items-center gap-3 border-t border-chocolate/10 pt-6"><button class="admin-primary" :disabled="form.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ form.processing ? 'Saving…' : 'Save changes' }}</button><button v-if="customized" type="button" class="admin-secondary admin-danger" :disabled="form.processing" @click="reset"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Restore original copy</button></div>
    </form>
</template>

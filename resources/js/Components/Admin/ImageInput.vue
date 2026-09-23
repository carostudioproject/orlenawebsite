<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
const props = defineProps<{ id: string; label: string; current: string | null; error?: string }>();
const emit = defineEmits<{ select: [file: File | null] }>();
const preview = ref<string | null>(null);
const fileName = ref<string | null>(null);
const input = ref<HTMLInputElement>();
const shown = computed(() => preview.value ?? props.current);
function pick(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = file ? URL.createObjectURL(file) : null;
    fileName.value = file?.name ?? null;
    emit('select', file);
}
function clear() {
    if (input.value) input.value.value = '';
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = null;
    fileName.value = null;
    emit('select', null);
}
onBeforeUnmount(() => { if (preview.value) URL.revokeObjectURL(preview.value); });
</script>
<template>
    <div class="space-y-2">
        <p :id="`${id}-label`" class="m-0 text-sm font-bold">{{ label }}</p>
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex h-24 w-32 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-chocolate/10 bg-cream">
                <img v-if="shown" :src="shown" alt="" class="size-full object-cover">
                <i v-else class="fa-solid fa-image text-2xl text-chocolate/30" aria-hidden="true"></i>
            </div>
            <div class="min-w-0 flex-1 space-y-2">
                <!-- The native input stays in the tab order and keeps its label; only its default look is replaced. -->
                <input :id="id" ref="input" type="file" class="peer sr-only" accept="image/jpeg,image/png,image/webp" :aria-labelledby="`${id}-label`" :aria-describedby="`${id}-hint${error ? ` ${id}-error` : ''}`" @change="pick">
                <div class="flex flex-wrap items-center gap-2">
                    <label :for="id" class="admin-action cursor-pointer peer-focus-visible:outline-2 peer-focus-visible:outline-chocolate"><i class="fa-solid fa-upload" aria-hidden="true"></i>{{ shown ? 'Replace image' : 'Choose image' }}</label>
                    <button v-if="fileName" type="button" class="admin-action-icon admin-danger" :aria-label="`Remove ${fileName}`" title="Remove selection" @click="clear"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </div>
                <p v-if="fileName" class="m-0 truncate text-xs font-bold">{{ fileName }}</p>
                <p :id="`${id}-hint`" class="admin-muted m-0 text-xs">JPG, PNG or WebP, up to 4 MB.{{ current ? ' Leave empty to keep the current image.' : '' }}</p>
            </div>
        </div>
        <p v-if="error" :id="`${id}-error`" class="admin-error-text text-sm" role="alert">{{ error }}</p>
    </div>
</template>

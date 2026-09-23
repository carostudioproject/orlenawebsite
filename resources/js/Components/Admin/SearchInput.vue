<script setup lang="ts">
defineProps<{ id: string; label: string; placeholder?: string; loading?: boolean }>();
const model = defineModel<string>({ required: true });
</script>
<template>
    <div class="min-w-48 flex-1">
        <label :for="id" class="mb-2 block text-sm">{{ label }}</label>
        <div class="relative">
            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-chocolate/45" aria-hidden="true"></i>
            <input :id="id" v-model="model" type="search" maxlength="100" autocomplete="off" spellcheck="false" :placeholder="placeholder" class="admin-search-input">
            <span class="absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1">
                <i v-if="loading" class="fa-solid fa-circle-notch fa-spin text-sm text-chocolate/50" aria-hidden="true"></i>
                <button v-if="model" type="button" class="admin-action-icon size-7! border-0" :aria-label="`Clear ${label.toLowerCase()}`" title="Clear" @click="model = ''"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </span>
        </div>
        <span class="sr-only" role="status" aria-live="polite">{{ loading ? 'Loading results…' : '' }}</span>
    </div>
</template>

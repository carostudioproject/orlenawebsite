<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { closeDialog, dialogState } from '../../Support/dialog';
// Native <dialog> gives a real modal: focus stays inside, Escape closes, and the page behind is inert.
const dialog = ref<HTMLDialogElement>();
const confirmButton = ref<HTMLButtonElement>();
const copyField = ref<HTMLInputElement>();
const copied = ref(false);
watch(() => dialogState.open, async open => {
    if (!open) { if (dialog.value?.open) dialog.value.close(); return; }
    copied.value = false;
    await nextTick();
    if (!dialog.value?.open) dialog.value?.showModal();
    if (dialogState.options.copyText) copyField.value?.select();
    else confirmButton.value?.focus();
});
async function copy() {
    copyField.value?.select();
    try { await navigator.clipboard.writeText(dialogState.options.copyText ?? ''); copied.value = true; } catch { copied.value = false; }
}
</script>
<template>
    <dialog ref="dialog" class="app-dialog" :aria-labelledby="'app-dialog-title'" :aria-describedby="dialogState.options.message ? 'app-dialog-message' : undefined" @cancel.prevent="closeDialog(false)" @click.self="closeDialog(false)">
        <div class="app-dialog-panel">
            <span class="app-dialog-icon" :class="dialogState.options.tone === 'danger' ? 'is-danger' : ''" aria-hidden="true"><i class="fa-solid" :class="dialogState.options.icon ?? (dialogState.options.tone === 'danger' ? 'fa-triangle-exclamation' : 'fa-circle-question')"></i></span>
            <h2 id="app-dialog-title" class="m-0 text-xl">{{ dialogState.options.title }}</h2>
            <p v-if="dialogState.options.message" id="app-dialog-message" class="admin-muted m-0 text-sm leading-relaxed">{{ dialogState.options.message }}</p>
            <div v-if="dialogState.options.copyText" class="flex gap-2"><label for="app-dialog-copy" class="sr-only">Text to copy</label><input id="app-dialog-copy" ref="copyField" :value="dialogState.options.copyText" readonly class="min-w-0 flex-1" @focus="copyField?.select()"><button type="button" class="admin-secondary shrink-0" @click="copy"><i class="fa-solid" :class="copied ? 'fa-check' : 'fa-copy'" aria-hidden="true"></i>{{ copied ? 'Copied' : 'Copy' }}</button></div>
            <div class="mt-2 flex flex-wrap justify-end gap-2">
                <button v-if="dialogState.options.cancelLabel" type="button" class="admin-secondary" @click="closeDialog(false)">{{ dialogState.options.cancelLabel }}</button>
                <button ref="confirmButton" type="button" class="admin-primary" :class="{ 'is-danger': dialogState.options.tone === 'danger' }" @click="closeDialog(true)">{{ dialogState.options.confirmLabel }}</button>
            </div>
        </div>
    </dialog>
</template>

<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
// Success messages from the server appear as a short popup instead of a banner; they also stay readable by screen readers.
const page = usePage<{ flash?: { success?: string } }>();
const message = ref<string | null>(page.props.flash?.success ?? null);
let timer: ReturnType<typeof setTimeout> | undefined;
function show(text: string | null | undefined) {
    clearTimeout(timer);
    message.value = text ?? null;
    if (message.value) timer = setTimeout(() => { message.value = null; }, 4500);
}
show(message.value);
// Hovering keeps the toast open; leaving restarts the countdown.
function pause() { clearTimeout(timer); }
// Listening to each response (not the prop) shows the toast again even when the same message repeats.
const off = router.on('success', event => show((event.detail.page.props.flash as { success?: string } | undefined)?.success));
onBeforeUnmount(() => { off(); clearTimeout(timer); });
</script>
<template>
    <div class="pointer-events-none fixed inset-x-4 bottom-4 z-[60] flex justify-center sm:inset-x-auto sm:bottom-auto sm:right-6 sm:top-20" aria-live="polite" role="status">
        <Transition name="toast">
            <div v-if="message" class="app-toast pointer-events-auto" @mouseenter="pause" @mouseleave="show(message)">
                <span class="app-toast-icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <p class="m-0 flex-1 text-sm font-bold">{{ message }}</p>
                <button type="button" class="admin-icon-button inline-flex size-8! text-sm" aria-label="Dismiss notification" @click="message = null"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
        </Transition>
    </div>
</template>

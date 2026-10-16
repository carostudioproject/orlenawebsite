<script setup lang="ts">
import { computed, ref } from 'vue';
import { rupiah } from '../../Support/money';
import { clampQuantity } from '../../Support/cart';
import type { CustomerInput } from '../../Types/ordering';
import LineInput from './LineInput.vue';
// The cart on every screen size: a bar at the bottom while it has items, opening a panel (bottom sheet on phones,
// side drawer on wide screens) to change quantities. Shared by the order form and "Tambah pesanan".
export interface PanelLine { key: string; index: number; name: string; detail: string; contentsText: string; unit: number; quantity: number; error?: string | null; input?: CustomerInput | null; inputTexts?: string[]; inputError?: (unit: number) => string | undefined }
const props = defineProps<{ lines: PanelLine[]; subtotal: number; disabled?: boolean; title?: string }>();
const emit = defineEmits<{ quantity: [index: number, quantity: number]; remove: [index: number]; text: [index: number, unit: number, text: string] }>();

const count = computed(() => props.lines.reduce((sum, line) => sum + line.quantity, 0));
const dialog = ref<HTMLDialogElement | null>(null);
function open() {
    dialog.value?.showModal();
    document.documentElement.classList.add('overflow-hidden');
}
function close() { dialog.value?.close(); }
function onClose() { document.documentElement.classList.remove('overflow-hidden'); }
function backdrop(event: MouseEvent) { if (event.target === dialog.value) close(); }
function remove(index: number) {
    emit('remove', index);
    if (props.lines.length <= 1) close();
}
defineExpose({ open, close });
</script>
<template>
    <!-- Rendered in <body>: a transformed ancestor (page transitions) would otherwise pin "fixed" to the page instead of the screen. -->
    <Teleport to="body">
    <div v-if="lines.length" class="admin-shell order-cartbar">
        <div class="mx-auto flex w-full max-w-6xl items-center gap-3 px-4">
            <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" @click="open">
                <span class="relative flex size-11 shrink-0 items-center justify-center rounded-full bg-cream"><i class="fa-solid fa-basket-shopping" aria-hidden="true"></i><span class="order-cartbar-count">{{ count }}</span></span>
                <span class="min-w-0"><span class="block text-xs text-chocolate/65">{{ count }} item di {{ title ?? 'keranjang' }}</span><span class="block text-lg font-bold tabular-nums">{{ rupiah(subtotal) }}</span></span>
            </button>
            <button type="button" class="admin-primary shrink-0" @click="open">Lihat keranjang</button>
        </div>
    </div>
    </Teleport>

    <dialog ref="dialog" class="order-sheet is-drawer" aria-labelledby="cart-panel-title" @close="onClose" @click="backdrop">
        <div class="flex items-center justify-between gap-3 border-b border-chocolate/10 px-5 py-4">
            <h2 id="cart-panel-title" class="m-0 flex items-center gap-2 text-xl">{{ title ? title.charAt(0).toUpperCase() + title.slice(1) : 'Keranjang' }} <span class="rounded-full bg-almond px-2.5 py-0.5 text-sm">{{ count }}</span></h2>
            <button type="button" class="admin-action-icon" aria-label="Tutup" @click="close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="order-sheet-body">
            <p v-if="!lines.length" class="admin-muted py-6 text-center text-sm">Keranjang masih kosong.</p>
            <ul v-else class="m-0 list-none divide-y divide-chocolate/10 p-0">
                <li v-for="line in lines" :key="line.key" class="space-y-2 py-3 text-sm">
                    <div class="flex justify-between gap-3">
                        <div class="min-w-0">
                            <p class="m-0 font-bold">{{ line.name }}</p>
                            <p v-if="line.detail" class="m-0 text-xs text-matcha">{{ line.detail }}</p>
                            <p v-if="line.contentsText" class="m-0 mt-0.5 text-xs text-chocolate/75"><i class="fa-solid fa-gift" aria-hidden="true"></i> Isi: {{ line.contentsText }}</p>
                        </div>
                        <span class="shrink-0 font-bold tabular-nums">{{ rupiah(line.unit * line.quantity) }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <div class="order-stepper is-small" role="group" :aria-label="`Jumlah ${line.name}`">
                            <button type="button" aria-label="Kurangi" :disabled="line.quantity <= 1 || disabled" @click="emit('quantity', line.index, line.quantity - 1)"><i class="fa-solid fa-minus" aria-hidden="true"></i></button>
                            <input :value="line.quantity" class="order-qty" type="number" min="1" max="99" inputmode="numeric" :aria-label="`Jumlah ${line.name}`" :disabled="disabled" @change="emit('quantity', line.index, clampQuantity(Number(($event.target as HTMLInputElement).value)))">
                            <button type="button" aria-label="Tambah" :disabled="line.quantity >= 99 || disabled" @click="emit('quantity', line.index, line.quantity + 1)"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>
                        </div>
                        <button type="button" class="admin-action-icon admin-danger" :aria-label="`Hapus ${line.name}`" title="Hapus" :disabled="disabled" @click="remove(line.index)"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
                    </div>
                    <LineInput v-if="line.input" :id="`panel-input-${line.index}`" :texts="line.inputTexts ?? []" :input="line.input" :quantity="line.quantity" :error-for="line.inputError" :disabled="disabled" @update="(unit, text) => emit('text', line.index, unit, text)" />
                    <p v-if="line.error" class="admin-error-text m-0 text-xs" role="alert">{{ line.error }}</p>
                </li>
            </ul>
        </div>
        <div class="order-sheet-footer">
            <div class="min-w-0">
                <p class="m-0 text-xs text-chocolate/65">Subtotal</p>
                <p class="m-0 text-lg font-bold tabular-nums">{{ rupiah(subtotal) }}</p>
            </div>
            <slot name="action" :close="close" />
        </div>
    </dialog>
</template>

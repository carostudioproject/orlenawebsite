<script setup lang="ts">
import type { CustomerInput } from '../../Types/ordering';
// The texts a product asks for on its cart line (greeting card, writing on a cake…): one field per unit, so 3 cards get 3 greetings.
// Shared by the cart panel and both summaries; `id` prefixes each field's id (`${id}-${unit}`).
const props = defineProps<{ id: string; input: CustomerInput; texts: string[]; quantity: number; errorFor?: (unit: number) => string | undefined | null; disabled?: boolean }>();
const emit = defineEmits<{ update: [unit: number, text: string] }>();
const label = (unit: number) => `${props.input.label}${props.quantity > 1 ? ` ${unit + 1}` : ''}`;
</script>
<template>
    <div class="space-y-2">
        <div v-for="unit in quantity" :key="unit" class="space-y-1">
            <label :for="`${id}-${unit - 1}`" class="block text-xs font-bold">{{ label(unit - 1) }}<span v-if="!input.required" class="font-normal text-chocolate/60"> (opsional)</span></label>
            <textarea :id="`${id}-${unit - 1}`" :value="texts[unit - 1] ?? ''" rows="2" :maxlength="input.max" :placeholder="input.placeholder ?? ''" :disabled="disabled" :required="input.required" class="order-line-input" :aria-invalid="!!errorFor?.(unit - 1)" :aria-describedby="`${id}-${unit - 1}-hint`" @input="emit('update', unit - 1, ($event.target as HTMLTextAreaElement).value)"></textarea>
            <p :id="`${id}-${unit - 1}-hint`" class="m-0 text-xs text-chocolate/60">{{ (texts[unit - 1] ?? '').length }}/{{ input.max }} karakter</p>
            <p v-if="errorFor?.(unit - 1)" class="admin-error-text m-0 text-xs" role="alert">{{ errorFor(unit - 1) }}</p>
        </div>
    </div>
</template>

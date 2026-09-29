<script setup lang="ts">
import { computed } from 'vue';
import type { OrderItem, Preorder } from '../Types/ordering';
import { rupiah } from '../Support/money';
// Customer pages use Indonesian; the dashboard passes lang="en".
const props = defineProps<{ order: Pick<Preorder, 'subtotal' | 'delivery_fee' | 'total'> & { items: Pick<OrderItem, 'id' | 'product_name_snapshot' | 'category_snapshot' | 'variant_snapshot' | 'quantity' | 'subtotal'>[] }; lang?: 'id' | 'en' }>();
const t = computed(() => (props.lang === 'en'
    ? { fee: 'Delivery fee', pending: 'Confirmed by staff', total: 'Initial total', note: 'The initial total excludes the delivery fee.' }
    : { fee: 'Ongkir', pending: 'Dikonfirmasi staff', total: 'Total awal', note: 'Total awal belum termasuk ongkir.' }));
</script>
<template>
    <div class="space-y-5"><div v-for="item in order.items" :key="item.id" class="flex justify-between gap-4 border-b border-chocolate/10 pb-4 text-sm"><div><p class="font-bold">{{ item.quantity }} × {{ item.product_name_snapshot }}</p><p class="m-0 text-xs text-matcha">{{ item.category_snapshot }}{{ item.variant_snapshot ? ` · ${item.variant_snapshot}` : '' }}</p></div><span class="whitespace-nowrap">{{ rupiah(item.subtotal) }}</span></div><dl class="space-y-3 text-sm"><div class="flex justify-between gap-4"><dt>Subtotal</dt><dd>{{ rupiah(order.subtotal) }}</dd></div><div class="flex justify-between gap-4"><dt>{{ t.fee }}</dt><dd>{{ order.delivery_fee === null ? t.pending : rupiah(order.delivery_fee) }}</dd></div><div class="flex justify-between gap-4 text-base font-bold"><dt>{{ t.total }}</dt><dd>{{ rupiah(order.total) }}</dd></div></dl><p v-if="order.delivery_fee === null" class="text-xs text-chocolate/60">{{ t.note }}</p></div>
</template>

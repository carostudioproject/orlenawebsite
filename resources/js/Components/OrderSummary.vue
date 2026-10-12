<script setup lang="ts">
import { computed } from 'vue';
import type { OrderItem, Preorder } from '../Types/ordering';
import { rupiah } from '../Support/money';
// Customer pages use Indonesian; the dashboard passes lang="en".
type SummaryItem = Pick<OrderItem, 'id' | 'parent_item_id' | 'product_name_snapshot' | 'category_snapshot' | 'variant_snapshot' | 'quantity' | 'subtotal'>;
const props = defineProps<{ order: Pick<Preorder, 'subtotal' | 'delivery_fee' | 'total'> & { items: SummaryItem[] }; lang?: 'id' | 'en' }>();
const t = computed(() => (props.lang === 'en'
    ? { fee: 'Delivery fee', pending: 'Confirmed by staff', total: 'Initial total', note: 'The initial total excludes the delivery fee.', contents: 'Contents' }
    : { fee: 'Ongkir', pending: 'Dikonfirmasi staff', total: 'Total awal', note: 'Total awal belum termasuk ongkir.', contents: 'Isi' }));
// Hampers/box contents are listed under their line, whose amount includes them.
const lines = computed(() => props.order.items.filter(item => !item.parent_item_id).map(item => {
    const contents = props.order.items.filter(child => child.parent_item_id === item.id);
    return { ...item, contents, total: item.subtotal + contents.reduce((sum, child) => sum + child.subtotal, 0) };
}));
</script>
<template>
    <div class="space-y-5">
        <div v-for="item in lines" :key="item.id" class="flex justify-between gap-4 border-b border-chocolate/10 pb-4 text-sm">
            <div>
                <p class="font-bold">{{ item.quantity }} × {{ item.product_name_snapshot }}</p>
                <p class="m-0 text-xs text-matcha">{{ item.category_snapshot }}{{ item.variant_snapshot ? ` · ${item.variant_snapshot}` : '' }}</p>
                <ul v-if="item.contents.length" class="m-0 mt-1.5 list-none space-y-0.5 p-0 text-xs text-chocolate/75" :aria-label="`${t.contents} ${item.product_name_snapshot}`">
                    <li v-for="child in item.contents" :key="child.id"><i class="fa-solid fa-gift" aria-hidden="true"></i> {{ child.quantity }} × {{ child.product_name_snapshot }}{{ child.variant_snapshot ? ` (${child.variant_snapshot})` : '' }}<span v-if="child.subtotal"> · {{ rupiah(child.subtotal) }}</span></li>
                </ul>
            </div>
            <span class="whitespace-nowrap">{{ rupiah(item.total) }}</span>
        </div>
        <dl class="space-y-3 text-sm"><div class="flex justify-between gap-4"><dt>Subtotal</dt><dd>{{ rupiah(order.subtotal) }}</dd></div><div class="flex justify-between gap-4"><dt>{{ t.fee }}</dt><dd>{{ order.delivery_fee === null ? t.pending : rupiah(order.delivery_fee) }}</dd></div><div class="flex justify-between gap-4 text-base font-bold"><dt>{{ t.total }}</dt><dd>{{ rupiah(order.total) }}</dd></div></dl>
        <p v-if="order.delivery_fee === null" class="text-xs text-chocolate/60">{{ t.note }}</p>
    </div>
</template>

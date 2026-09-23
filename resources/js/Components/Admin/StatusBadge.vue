<script setup lang="ts">
import { computed } from 'vue';
import { orderStatusLabel, paymentStatusLabel, syncStatusLabels } from '../../Support/orderStatus';
const props = defineProps<{ status: string; kind: 'order' | 'payment' | 'sync' }>();
// Brand palette by meaning; the text label always carries the status, so colour is never the only cue.
const tone: Record<string, string> = {
    pending_review: 'wait', not_created: 'wait', creating: 'wait', pending: 'wait', waiting_config: 'wait', needs_mapping: 'wait',
    confirmed: 'info', refunded: 'info', skipped: 'info',
    processing: 'progress', ready: 'progress', delivering: 'progress',
    completed: 'done', paid: 'done', synced: 'done',
    cancelled: 'stop', failed: 'stop', expired: 'stop', creation_failed: 'stop',
};
const styles: Record<string, { background: string; dot: string }> = {
    wait: { background: '#f7dbb3', dot: '#3b0304' }, info: { background: '#75a4cc33', dot: '#75a4cc' },
    progress: { background: '#f3a8ac59', dot: '#db3e4c' }, done: { background: '#4d714126', dot: '#4d7141' }, stop: { background: '#db3e4c1f', dot: '#a3202d' },
};
const style = computed(() => styles[tone[props.status] ?? 'wait']);
const label = computed(() => (props.kind === 'order' ? orderStatusLabel(props.status) : props.kind === 'sync' ? syncStatusLabels[props.status] ?? props.status : paymentStatusLabel(props.status)));
</script>
<template>
    <span class="inline-flex items-center gap-2 whitespace-nowrap rounded-full px-3 py-1 text-xs font-bold" :style="{ background: style.background }"><span aria-hidden="true" class="size-2 rounded-full" :style="{ background: style.dot }"></span>{{ label }}</span>
</template>

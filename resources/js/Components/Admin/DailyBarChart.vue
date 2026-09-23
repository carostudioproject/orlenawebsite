<script setup lang="ts">
import { computed, ref } from 'vue';
// Single-series bar chart: one brand hue, recessive grid, per-bar hover tooltip, and a table for assistive tech.
const props = defineProps<{ data: { date: string; total: number }[]; caption: string; unit: string; format?: (value: number) => string; tick?: (value: number) => string }>();
const value = (total: number) => (props.format ? props.format(total) : `${total} ${props.unit}`);
const tickLabel = (total: number) => (props.tick ? props.tick(total) : String(total));
// Long ranges label every n-th bar so dates never overlap.
const labelEvery = computed(() => Math.max(2, Math.ceil(props.data.length / 10)));
const active = ref<number | null>(null);
const niceMax = computed(() => {
    const max = Math.max(...props.data.map(point => point.total), 0);
    if (max <= 4) return 4;
    const step = 10 ** Math.floor(Math.log10(max));
    return Math.ceil(max / step) * step;
});
const ticks = computed(() => [niceMax.value, niceMax.value / 2, 0]);
const day = (date: string, options: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat('en-GB', { ...options, timeZone: 'UTC' }).format(new Date(date + 'T00:00:00Z'));
const short = (date: string) => day(date, { day: 'numeric', month: 'short' });
const long = (date: string) => day(date, { weekday: 'long', day: 'numeric', month: 'long' });
</script>
<template>
    <figure class="m-0">
        <div class="flex gap-3">
            <div class="admin-muted flex h-48 flex-col justify-between pb-0 text-right text-xs tabular-nums" aria-hidden="true"><span v-for="tick in ticks" :key="tick" class="-translate-y-1/2 leading-none">{{ tickLabel(tick) }}</span></div>
            <div class="relative flex-1">
                <div class="pointer-events-none absolute inset-x-0 top-0 h-48" aria-hidden="true"><div v-for="(tick, index) in ticks" :key="tick" class="absolute inset-x-0 border-t" :class="index === ticks.length - 1 ? 'border-chocolate/30' : 'border-dashed border-chocolate/10'" :style="{ top: `${(index / (ticks.length - 1)) * 100}%` }"></div></div>
                <div class="relative flex h-48 items-end gap-0.5" aria-hidden="true" @mouseleave="active = null">
                    <div v-for="(point, index) in data" :key="point.date" class="group relative flex h-full flex-1 items-end justify-center" @mouseenter="active = index">
                        <div class="w-full max-w-7 rounded-t transition-colors duration-150" :class="active === index ? 'bg-chocolate' : 'bg-chocolate/80'" :style="{ height: `${(point.total / niceMax) * 100}%` }"></div>
                        <div v-if="active === index" class="absolute z-10 whitespace-nowrap rounded-lg bg-chocolate px-3 py-2 text-xs text-cream shadow-lg" :class="index >= data.length - 3 ? 'right-0' : index < 2 ? 'left-0' : 'left-1/2 -translate-x-1/2'" :style="{ bottom: `calc(${(point.total / niceMax) * 100}% + 8px)` }"><span class="block font-bold">{{ value(point.total) }}</span>{{ long(point.date) }}</div>
                    </div>
                </div>
                <div class="admin-muted mt-2 flex gap-0.5 text-[11px]" aria-hidden="true"><span v-for="(point, index) in data" :key="point.date" class="flex-1 whitespace-nowrap text-center" :class="data.length <= 14 && index % 2 ? 'invisible sm:visible' : ''">{{ data.length <= 7 || (data.length <= 14 ? index % 2 === 0 : index % labelEvery === 0) ? short(point.date) : '' }}</span></div>
            </div>
        </div>
        <table class="sr-only"><caption>{{ caption }}</caption><thead><tr><th scope="col">Date</th><th scope="col">{{ unit }}</th></tr></thead><tbody><tr v-for="point in data" :key="point.date"><td>{{ long(point.date) }}</td><td>{{ value(point.total) }}</td></tr></tbody></table>
    </figure>
</template>

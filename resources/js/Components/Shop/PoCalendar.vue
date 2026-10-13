<script setup lang="ts">
import { computed, ref, watch } from 'vue';

export interface CalendarDay { date: string; status: 'open' | 'cutoff' | 'closed' | 'full'; reason: string | null; slots: { time: string; left: number | null }[] }
const props = defineProps<{ days: CalendarDay[]; modelValue: string; disabled?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

// A month grid (Monday first) over the dates the server sent: from tomorrow up to the 30-day limit.
const byDate = computed(() => new Map(props.days.map(day => [day.date, day])));
const months = computed(() => [...new Set(props.days.map(day => day.date.slice(0, 7)))]);
const month = ref('');
watch(months, list => { if (!list.includes(month.value)) month.value = props.modelValue.slice(0, 7) && list.includes(props.modelValue.slice(0, 7)) ? props.modelValue.slice(0, 7) : list[0] ?? ''; }, { immediate: true });
const monthIndex = computed(() => months.value.indexOf(month.value));
const monthLabel = computed(() => (month.value ? new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${month.value}-01T00:00:00Z`)) : ''));
const cells = computed(() => {
    if (!month.value) return [];
    const first = new Date(`${month.value}-01T00:00:00Z`);
    const blanks = (first.getUTCDay() + 6) % 7;
    const length = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0)).getUTCDate();
    return [...Array(blanks).fill(null), ...Array.from({ length }, (_, index) => {
        const date = `${month.value}-${String(index + 1).padStart(2, '0')}`;
        return { date, day: index + 1, info: byDate.value.get(date) ?? null };
    })];
});
const weekdays = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
const longDate = (date: string) => new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));

// Unavailable dates stay focusable so a tap explains why (a disabled button would say nothing on a phone).
const note = ref<string | null>(null);
function pick(cell: { date: string; info: CalendarDay | null }) {
    if (props.disabled) return;
    if (cell.info?.status === 'open') {
        note.value = null;
        emit('update:modelValue', cell.date);
        return;
    }
    note.value = `${longDate(cell.date)}: ${cell.info?.reason ?? 'Di luar rentang pemesanan (paling lama 30 hari ke depan).'}`;
}
const label = (cell: { date: string; info: CalendarDay | null }) => `${longDate(cell.date)}${cell.info?.status === 'open' ? '' : `, tidak tersedia${cell.info?.reason ? `: ${cell.info.reason}` : ''}`}`;
</script>
<template>
    <div class="po-calendar" :class="{ 'is-disabled': disabled }">
        <div class="flex items-center justify-between gap-2">
            <button type="button" class="po-calendar-nav" :disabled="monthIndex <= 0" aria-label="Bulan sebelumnya" @click="month = months[monthIndex - 1]"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
            <p class="m-0 font-bold capitalize" aria-live="polite">{{ monthLabel }}</p>
            <button type="button" class="po-calendar-nav" :disabled="monthIndex < 0 || monthIndex >= months.length - 1" aria-label="Bulan berikutnya" @click="month = months[monthIndex + 1]"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
        </div>
        <div class="mt-3 grid grid-cols-7 gap-1 text-center" role="grid" :aria-label="`Tanggal PO, ${monthLabel}`">
            <span v-for="weekday in weekdays" :key="weekday" class="pb-1 text-[11px] font-bold uppercase text-chocolate/55" aria-hidden="true">{{ weekday }}</span>
            <template v-for="(cell, index) in cells" :key="cell?.date ?? `blank-${index}`">
                <span v-if="!cell" aria-hidden="true"></span>
                <button v-else type="button" class="po-calendar-day" :class="[`is-${cell.info?.status ?? 'out'}`, { 'is-selected': cell.date === modelValue }]"
                    :aria-pressed="cell.date === modelValue" :aria-disabled="cell.info?.status !== 'open'" :aria-label="label(cell)" @click="pick(cell)">{{ cell.day }}</button>
            </template>
        </div>
        <p v-if="note" class="m-0 mt-3 rounded-xl bg-cream p-2.5 text-xs" role="status"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> {{ note }}</p>
        <ul class="m-0 mt-3 flex list-none flex-wrap gap-x-4 gap-y-1 p-0 text-[11px] text-chocolate/65">
            <li><span class="po-calendar-key is-open" aria-hidden="true"></span>Tersedia</li>
            <li><span class="po-calendar-key is-full" aria-hidden="true"></span>Penuh</li>
            <li><span class="po-calendar-key is-closed" aria-hidden="true"></span>Tutup / lewat batas</li>
        </ul>
    </div>
</template>

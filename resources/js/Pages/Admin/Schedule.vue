<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import Field from '../../Components/Admin/Field.vue';
import { confirmDialog } from '../../Support/dialog';
defineOptions({ layout: AdminLayout });
interface Settings { cutoff_time: string; closed_weekdays: number[]; daily_capacity: number | null }
const props = defineProps<{
    settings: Settings; weekdays: { value: number; label: string }[]; earliest: string;
    closedDates: { id: number; date: string; reason: string | null }[]; load: { date: string; total: number }[];
}>();

const form = useForm({ cutoff_time: props.settings.cutoff_time, closed_weekdays: [...props.settings.closed_weekdays], daily_capacity: props.settings.daily_capacity ?? ('' as number | '') });
watch(() => props.settings, settings => { form.defaults({ cutoff_time: settings.cutoff_time, closed_weekdays: [...settings.closed_weekdays], daily_capacity: settings.daily_capacity ?? '' }); form.reset(); });
const closing = useForm({ date: '', reason: '' });
const formatDate = (date: string) => new Intl.DateTimeFormat('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Makassar' }).format(new Date());

function save() { form.put('/admin/schedule', { preserveScroll: true }); }
function closeDate() { closing.post('/admin/schedule/closed-dates', { preserveScroll: true, onSuccess: () => closing.reset() }); }
async function reopen(closed: { id: number; date: string }) {
    if (await confirmDialog({ title: `Reopen ${formatDate(closed.date)}?`, message: 'Customers can choose this date on the order form again.', confirmLabel: 'Reopen', icon: 'fa-calendar-check' })) {
        router.delete(`/admin/schedule/closed-dates/${closed.id}`, { preserveScroll: true });
    }
}
const loadTone = (total: number) => (!props.settings.daily_capacity ? '' : total >= props.settings.daily_capacity ? 'is-full' : total >= props.settings.daily_capacity * 0.8 ? 'is-near' : '');
</script>
<template>
    <Head title="PO schedule · Orlena" />
    <p class="admin-eyebrow">Settings</p><h1 class="text-3xl font-bold">PO schedule</h1>
    <p class="admin-muted mt-2 text-sm">Set the order deadline, closed days and daily capacity. Earliest PO date right now: <strong>{{ formatDate(earliest) }}</strong>.</p>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <section class="admin-card space-y-5" aria-labelledby="rules-title">
            <h2 id="rules-title" class="m-0 text-xl">Ordering rules</h2>
            <form class="space-y-5" @submit.prevent="save">
                <div v-if="form.hasErrors" role="alert" class="admin-alert admin-alert-error"><ul class="list-disc pl-5"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>
                <Field id="cutoff_time" label="Order deadline (D-1, WITA)" hint="Last time to order for a date, on the day before. Also the limit for payment links and customer additions." :error="form.errors.cutoff_time"><template #default="{ describedBy }"><input id="cutoff_time" v-model="form.cutoff_time" type="time" required step="60" class="max-w-40" :aria-describedby="describedBy" :aria-invalid="!!form.errors.cutoff_time"></template></Field>
                <fieldset class="space-y-2">
                    <legend class="mb-2 text-sm font-bold">Closed every week</legend>
                    <div class="flex flex-wrap gap-2"><label v-for="day in weekdays" :key="day.value" class="order-size"><input v-model="form.closed_weekdays" type="checkbox" :value="day.value" class="sr-only"><span>{{ day.label }}</span></label></div>
                    <p class="admin-muted m-0 text-xs">Selected days cannot be chosen as a pickup/delivery date.</p>
                </fieldset>
                <Field id="daily_capacity" label="Orders per day capacity (optional)" hint="Only a warning for staff during review. Customer orders are never rejected automatically." :error="form.errors.daily_capacity"><template #default="{ describedBy }"><input id="daily_capacity" v-model="form.daily_capacity" type="number" min="1" max="10000" class="max-w-40" placeholder="No limit" :aria-describedby="describedBy" :aria-invalid="!!form.errors.daily_capacity"></template></Field>
                <button class="admin-primary" :disabled="form.processing || !form.isDirty"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ form.processing ? 'Saving…' : 'Save rules' }}</button>
            </form>
        </section>

        <section class="admin-card space-y-5 self-start" aria-labelledby="closed-title">
            <div><h2 id="closed-title" class="m-0 text-xl">Closed dates</h2><p class="admin-muted mt-1 text-sm">Holidays, events or fully booked production days. Existing orders are not changed.</p></div>
            <form class="grid grid-cols-1 items-end gap-3 sm:grid-cols-[auto_1fr_auto]" @submit.prevent="closeDate">
                <div><label for="closed_date" class="mb-2 block text-sm">Date</label><input id="closed_date" v-model="closing.date" type="date" required :min="today" :aria-invalid="!!closing.errors.date"></div>
                <div><label for="closed_reason" class="mb-2 block text-sm">Reason (optional)</label><input id="closed_reason" v-model="closing.reason" maxlength="160" placeholder="E.g. Nyepi"></div>
                <button class="admin-primary" :disabled="closing.processing"><i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i>Close date</button>
            </form>
            <p v-if="closing.errors.date || closing.errors.reason" role="alert" class="admin-error-text m-0 text-sm">{{ closing.errors.date ?? closing.errors.reason }}</p>
            <ul v-if="closedDates.length" class="divide-y divide-chocolate/10">
                <li v-for="closed in closedDates" :key="closed.id" class="flex items-center justify-between gap-3 py-2.5 text-sm">
                    <span><strong>{{ formatDate(closed.date) }}</strong><span v-if="closed.reason" class="admin-muted block text-xs">{{ closed.reason }}</span></span>
                    <button type="button" class="admin-action" :aria-label="`Reopen ${closed.date}`" @click="reopen(closed)"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i>Reopen</button>
                </li>
            </ul>
            <p v-else class="admin-muted m-0 text-sm">No closed dates yet.</p>
        </section>
    </div>

    <section class="admin-card mt-6" aria-labelledby="load-title">
        <h2 id="load-title" class="m-0 text-xl">Orders per PO date</h2>
        <p class="admin-muted mt-1 text-sm">The next 14 dates that have orders (excluding cancelled ones).<template v-if="settings.daily_capacity"> Capacity: {{ settings.daily_capacity }} orders/day.</template></p>
        <ul v-if="load.length" class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
            <li v-for="day in load" :key="day.date" class="schedule-load" :class="loadTone(day.total)">
                <span class="block text-xs">{{ formatDate(day.date).split(',')[0] }}</span><strong class="block">{{ day.date.slice(8) }}/{{ day.date.slice(5, 7) }}</strong>
                <span class="text-sm tabular-nums">{{ day.total }}<template v-if="settings.daily_capacity"> / {{ settings.daily_capacity }}</template> orders</span>
            </li>
        </ul>
        <p v-else class="admin-muted mt-4 text-sm">No orders for upcoming dates yet.</p>
    </section>
</template>

<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import Field from '../../Components/Field.vue';
import { confirmDialog } from '../../Support/dialog';
defineOptions({ layout: AdminLayout });
interface Settings { cutoff_time: string; payment_close_time: string; max_days: number; closed_weekdays: number[]; delivery_tolerance: number; delivery_min_order: number | null; delivery_radius: number | null; ck_whatsapp: string | null }
const props = defineProps<{
    settings: Settings; weekdays: { value: number; label: string }[]; earliest: string;
    closedDates: { id: number; date: string; reason: string | null }[]; load: { date: string; total: number }[];
}>();

const values = (settings: Settings) => ({
    cutoff_time: settings.cutoff_time, payment_close_time: settings.payment_close_time, max_days: settings.max_days, closed_weekdays: [...settings.closed_weekdays],
    delivery_tolerance: settings.delivery_tolerance, delivery_min_order: settings.delivery_min_order ?? ('' as number | ''), delivery_radius: settings.delivery_radius ?? ('' as number | ''), ck_whatsapp: settings.ck_whatsapp ?? '',
});
const form = useForm(values(props.settings));
watch(() => props.settings, settings => { form.defaults(values(settings)); form.reset(); });
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
</script>
<template>
    <Head title="PO schedule · Orlena" />
    <p class="admin-eyebrow">Settings</p><h1 class="text-3xl font-bold">PO schedule</h1>
    <p class="admin-muted mt-2 text-sm">Set the order deadline, payment close, closed days and delivery tolerance. Quotas and pickup hours are set per outlet. Earliest PO date right now: <strong>{{ formatDate(earliest) }}</strong>.</p>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <section class="admin-card space-y-5" aria-labelledby="rules-title">
            <h2 id="rules-title" class="m-0 text-xl">Ordering rules</h2>
            <form class="space-y-5" @submit.prevent="save">
                <div v-if="form.hasErrors" role="alert" class="admin-alert admin-alert-error"><ul class="list-disc pl-5"><li v-for="(error, key) in form.errors" :key="key">{{ error }}</li></ul></div>
                <Field id="cutoff_time" label="Order deadline (D-1, WITA)" hint="Production for a date closes at this time on the day before: later orders get the next open date, and customers can no longer add items." :error="form.errors.cutoff_time"><template #default="{ describedBy }"><input id="cutoff_time" v-model="form.cutoff_time" type="time" required step="60" class="max-w-40" :aria-describedby="describedBy" :aria-invalid="!!form.errors.cutoff_time"></template></Field>
                <Field id="payment_close_time" label="Payment close (D-1, WITA)" hint="Payment links (valid 2 hours) never last past this time. Paid between the order deadline and this time: the order moves to the next open date. Still unpaid at this time: cancelled automatically." :error="form.errors.payment_close_time"><template #default="{ describedBy }"><input id="payment_close_time" v-model="form.payment_close_time" type="time" required step="60" class="max-w-40" :aria-describedby="describedBy" :aria-invalid="!!form.errors.payment_close_time"></template></Field>
                <Field id="max_days" label="Order up to (days ahead)" hint="Customers can choose a date at most this many days from today." :error="form.errors.max_days"><template #default="{ describedBy }"><input id="max_days" v-model="form.max_days" type="number" required min="1" max="365" class="max-w-40" :aria-describedby="describedBy" :aria-invalid="!!form.errors.max_days"></template></Field>
                <fieldset class="space-y-2">
                    <legend class="mb-2 text-sm font-bold">Closed every week</legend>
                    <div class="flex flex-wrap gap-2"><label v-for="day in weekdays" :key="day.value" class="order-size"><input v-model="form.closed_weekdays" type="checkbox" :value="day.value" class="sr-only"><span>{{ day.label }}</span></label></div>
                    <p class="admin-muted m-0 text-xs">Selected days cannot be chosen as a pickup/delivery date.</p>
                </fieldset>
                <Field id="delivery_tolerance" label="Delivery tolerance (minutes)" hint="Shown on the order form and payment page: delivery may arrive this late after the estimated time, without compensation. Daily and hourly quotas are set per outlet." :error="form.errors.delivery_tolerance"><template #default="{ describedBy }"><input id="delivery_tolerance" v-model="form.delivery_tolerance" type="number" required min="0" max="600" class="max-w-40" :aria-describedby="describedBy" :aria-invalid="!!form.errors.delivery_tolerance"></template></Field>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <Field id="delivery_min_order" label="Delivery minimum order (Rp, optional)" hint="Delivery orders below this subtotal are refused on the order form. Empty: no minimum." :error="form.errors.delivery_min_order"><template #default="{ describedBy }"><input id="delivery_min_order" v-model="form.delivery_min_order" type="number" min="1" step="1000" placeholder="No minimum" :aria-describedby="describedBy" :aria-invalid="!!form.errors.delivery_min_order"></template></Field>
                    <Field id="delivery_radius" label="Delivery radius (km, optional)" hint="Shown on the order form. Staff check the distance from the location link during review." :error="form.errors.delivery_radius"><template #default="{ describedBy }"><input id="delivery_radius" v-model="form.delivery_radius" type="number" min="1" max="200" placeholder="Not set" :aria-describedby="describedBy" :aria-invalid="!!form.errors.delivery_radius"></template></Field>
                </div>
                <Field id="ck_whatsapp" label="Central kitchen (CK) WhatsApp" hint="Rekap CK → Send via WhatsApp opens a chat with this number. International format, e.g. 6281234567890. Empty: choose the contact each time." :error="form.errors.ck_whatsapp"><template #default="{ describedBy }"><input id="ck_whatsapp" v-model.trim="form.ck_whatsapp" inputmode="numeric" maxlength="16" class="max-w-60" placeholder="62…" :aria-describedby="describedBy" :aria-invalid="!!form.errors.ck_whatsapp"></template></Field>
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
        <p class="admin-muted mt-1 text-sm">The next 14 dates that have orders (excluding cancelled ones), all outlets. Each outlet has its own daily quota under Outlets.</p>
        <ul v-if="load.length" class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
            <li v-for="day in load" :key="day.date" class="schedule-load">
                <span class="block text-xs">{{ formatDate(day.date).split(',')[0] }}</span><strong class="block">{{ day.date.slice(8) }}/{{ day.date.slice(5, 7) }}</strong>
                <span class="text-sm tabular-nums">{{ day.total }} orders</span>
            </li>
        </ul>
        <p v-else class="admin-muted mt-4 text-sm">No orders for upcoming dates yet.</p>
    </section>
</template>

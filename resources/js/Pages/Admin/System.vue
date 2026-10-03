<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import StatusBadge from '../../Components/Admin/StatusBadge.vue';
import { witaTime } from '../../Support/orderStatus';
import type { AdminProps } from '../../Types/admin';
defineOptions({ layout: AdminLayout });
interface Failure { provider_order_id?: string; provider?: string; subject_id?: number; status: string; attempts?: number; last_error: string | null; updated_at: string }
const props = defineProps<{
    app: { env: string; debug: boolean; url: string; indexable: boolean; timezone: string; php: string; laravel: string };
    payments: {
        online: boolean; mode: string; production: boolean; clientId: boolean; secret: boolean; qrisMerchant: boolean; qrisTerminal: boolean;
        privateKey: boolean; publicKey: string | null; webhooks: { checkout: string; qris: string }; recentFailures: Failure[];
    };
    erzap: {
        enabled: boolean; baseUrl: string | null; token: boolean; defaultOutlet: string | null; salesUser: string | null; missing: string[];
        counts: Record<string, number>; lastSent: string | null; recentFailures: Failure[];
    };
    api: { tokens: number; base: string };
    cron: string;
    tasks: { key: string; label: string }[];
    log: { file: string | null; lines: string[] };
    queue: { failedJobs: number };
}>();
const page = usePage<AdminProps>();
const errors = computed(() => page.props.errors as Record<string, string>);

const running = ref<string | null>(null);
function run(task: string) {
    router.post('/admin/system/run', { task }, { preserveScroll: true, onStart: () => { running.value = task; }, onFinish: () => { running.value = null; } });
}
function generateKey() { router.post('/admin/system/snap-key', {}, { preserveScroll: true }); }
const copied = ref<string | null>(null);
async function copy(label: string, text: string) {
    try { await navigator.clipboard.writeText(text); copied.value = label; setTimeout(() => { copied.value = null; }, 1600); } catch { copied.value = null; }
}
const modeLabel: Record<string, string> = { qris: 'QRIS on /bayar (DOKU Direct API)', checkout: 'DOKU Checkout page', demo: 'Demo (simulated, no DOKU)' };
const check = (ok: boolean) => (ok ? 'fa-circle-check text-matcha' : 'fa-circle-xmark admin-error-text');
</script>
<template>
    <Head title="Developer tools · Orlena" />
    <p class="admin-eyebrow">Developer</p>
    <h1 class="text-3xl font-bold">Developer tools</h1>
    <p class="admin-muted mt-2 max-w-3xl text-sm">Status of payments, Erzap and the API on this server. Secrets are never shown; only whether they are set. Change values in the server <code>.env</code>, then run <em>Clear caches</em>.</p>
    <p v-if="errors.key" role="alert" class="admin-alert admin-alert-error mt-4">{{ errors.key }}</p>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        <section class="admin-card space-y-3 text-sm" aria-labelledby="app-title">
            <h2 id="app-title" class="text-xl">Application</h2>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
                <dt class="font-bold">Environment</dt><dd>{{ app.env }}<span v-if="app.debug" class="admin-error-text"> · debug ON</span></dd>
                <dt class="font-bold">URL</dt><dd class="break-all">{{ app.url }}</dd>
                <dt class="font-bold">Search engines</dt><dd>{{ app.indexable ? 'Allowed (SITE_INDEXABLE=true)' : 'Blocked (SITE_INDEXABLE=false)' }}</dd>
                <dt class="font-bold">Versions</dt><dd>PHP {{ app.php }} · Laravel {{ app.laravel }} · {{ app.timezone }}</dd>
                <dt class="font-bold">Failed jobs</dt><dd>{{ queue.failedJobs }}</dd>
            </dl>
            <div>
                <p class="m-0 font-bold">Cron (every minute)</p>
                <div class="mt-1 flex items-start gap-2"><code class="block flex-1 break-all rounded-lg bg-cream p-2 text-xs">{{ cron }}</code><button type="button" class="admin-action" @click="copy('cron', cron)"><i class="fa-solid fa-copy" aria-hidden="true"></i>{{ copied === 'cron' ? 'Copied' : 'Copy' }}</button></div>
            </div>
            <div>
                <p class="m-0 mb-2 font-bold">Maintenance</p>
                <div class="flex flex-wrap gap-2"><button v-for="task in tasks" :key="task.key" type="button" class="admin-secondary" :disabled="running !== null" @click="run(task.key)"><i class="fa-solid fa-play" aria-hidden="true"></i>{{ running === task.key ? 'Running…' : task.label }}</button></div>
            </div>
        </section>

        <section class="admin-card space-y-3 text-sm" aria-labelledby="pay-title">
            <h2 id="pay-title" class="text-xl">Payments (DOKU)</h2>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
                <dt class="font-bold">Online payment</dt><dd>{{ payments.online ? 'On' : 'Off: staff use Mark as paid' }}</dd>
                <dt class="font-bold">Mode</dt><dd>{{ modeLabel[payments.mode] ?? payments.mode }} · {{ payments.production ? 'production' : 'sandbox' }}</dd>
                <dt class="font-bold">Credentials</dt>
                <dd class="space-y-1">
                    <span class="block"><i class="fa-solid" :class="check(payments.clientId)" aria-hidden="true"></i> Client ID</span>
                    <span class="block"><i class="fa-solid" :class="check(payments.secret)" aria-hidden="true"></i> Secret key</span>
                    <span class="block"><i class="fa-solid" :class="check(payments.qrisMerchant)" aria-hidden="true"></i> QRIS Merchant ID</span>
                    <span class="block"><i class="fa-solid" :class="check(payments.qrisTerminal)" aria-hidden="true"></i> QRIS Terminal ID</span>
                    <span class="block"><i class="fa-solid" :class="check(payments.privateKey)" aria-hidden="true"></i> SNAP private key on server</span>
                </dd>
                <dt class="font-bold">Notification URLs</dt>
                <dd class="space-y-1 break-all"><span class="block">QRIS: <code>{{ payments.webhooks.qris }}</code></span><span class="block">Checkout: <code>{{ payments.webhooks.checkout }}</code></span></dd>
            </dl>
            <div>
                <p class="m-0 font-bold">SNAP public key (upload in the DOKU dashboard)</p>
                <template v-if="payments.publicKey">
                    <pre class="mt-1 max-h-40 overflow-auto rounded-lg bg-cream p-2 text-[11px] leading-snug">{{ payments.publicKey }}</pre>
                    <button type="button" class="admin-action mt-2" @click="copy('key', payments.publicKey)"><i class="fa-solid fa-copy" aria-hidden="true"></i>{{ copied === 'key' ? 'Copied' : 'Copy public key' }}</button>
                </template>
                <button v-else type="button" class="admin-secondary mt-2" @click="generateKey"><i class="fa-solid fa-key" aria-hidden="true"></i>Generate key pair</button>
            </div>
            <div v-if="payments.recentFailures.length">
                <p class="m-0 mb-1 font-bold">Recent payment errors</p>
                <ul class="m-0 list-none space-y-1 p-0 text-xs"><li v-for="(failure, index) in payments.recentFailures" :key="index"><span class="font-bold">{{ failure.provider_order_id }}</span> ({{ failure.provider }}, {{ witaTime(failure.updated_at) }}): {{ failure.last_error }}</li></ul>
            </div>
        </section>

        <section class="admin-card space-y-3 text-sm" aria-labelledby="erzap-title">
            <div class="flex items-center justify-between gap-3"><h2 id="erzap-title" class="text-xl">Erzap</h2><Link href="/admin/integrations" class="admin-action">Sync log</Link></div>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
                <dt class="font-bold">Sending</dt><dd>{{ erzap.enabled ? 'On' : 'Off' }}<span v-if="erzap.missing.length" class="admin-error-text"> · missing: {{ erzap.missing.join(', ') }}</span></dd>
                <dt class="font-bold">Server</dt><dd class="break-all">{{ erzap.baseUrl ?? '—' }}</dd>
                <dt class="font-bold">Token</dt><dd><i class="fa-solid" :class="check(erzap.token)" aria-hidden="true"></i> {{ erzap.token ? 'Set' : 'Not set' }}</dd>
                <dt class="font-bold">Outlet / sales ID</dt><dd>{{ erzap.defaultOutlet ?? '—' }} / {{ erzap.salesUser ?? '—' }}</dd>
                <dt class="font-bold">Queue</dt><dd class="flex flex-wrap gap-1.5"><span v-for="(total, status) in erzap.counts" :key="status" class="inline-flex items-center gap-1"><StatusBadge :status="String(status)" kind="sync" /> {{ total }}</span><span v-if="!Object.keys(erzap.counts).length">Empty</span></dd>
                <dt class="font-bold">Last sent</dt><dd>{{ witaTime(erzap.lastSent) }}</dd>
            </dl>
            <div v-if="erzap.recentFailures.length">
                <p class="m-0 mb-1 font-bold">Needs attention</p>
                <ul class="m-0 list-none space-y-1 p-0 text-xs"><li v-for="(failure, index) in erzap.recentFailures" :key="index"><Link :href="`/admin/orders/${failure.subject_id}`" class="font-bold underline">Order #{{ failure.subject_id }}</Link> ({{ failure.status }}, {{ failure.attempts }}×): {{ failure.last_error }}</li></ul>
            </div>
        </section>

        <section class="admin-card space-y-3 text-sm" aria-labelledby="api-title">
            <h2 id="api-title" class="text-xl">REST API</h2>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
                <dt class="font-bold">Base URL</dt><dd class="break-all"><code>{{ api.base }}</code></dd>
                <dt class="font-bold">Tokens</dt><dd>{{ api.tokens ? `${api.tokens} configured (API_TOKENS)` : 'None: protected endpoints are closed' }}</dd>
            </dl>
            <p class="admin-muted m-0 text-xs">Public: <code>/categories</code>, <code>/outlets</code>, <code>/products</code>, <code>/schedule</code>. With a Bearer token: <code>/orders</code>, <code>/orders/{code}</code>, <code>/reports/sales</code>. Details in <code>docs/rest-api.md</code>.</p>
        </section>
    </div>

    <section class="admin-card mt-6" aria-labelledby="log-title">
        <h2 id="log-title" class="text-xl">Error log <span v-if="log.file" class="admin-muted text-sm font-normal">({{ log.file }}, last lines; long tokens hidden)</span></h2>
        <pre v-if="log.lines.length" class="mt-3 max-h-96 overflow-auto rounded-lg bg-[#1b0102] p-3 text-[11px] leading-relaxed text-[#f7e5dc]">{{ log.lines.join('\n') }}</pre>
        <p v-else class="admin-muted mt-2 text-sm">The log is empty.</p>
    </section>
</template>

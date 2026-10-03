<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSync;
use App\Models\Payment;
use App\Services\Erzap\ErzapClient;
use App\Services\Payments\PaymentGateways;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Developer tools (Developer role only): configuration status of payments, Erzap and the API, recent
 * problems, the error log, and a few maintenance tasks. Secrets are never sent to the browser, only whether they are set.
 */
class SystemController extends Controller
{
    private const TASKS = [
        'erzap-sync' => ['erzap:sync', 'Send queued Erzap transactions'],
        'reconcile' => ['payments:reconcile', 'Check pending and expired payments'],
        'clear-cache' => ['optimize:clear', 'Clear config, route and view caches'],
    ];

    public function show(ErzapClient $erzap)
    {
        $doku = config('services.doku');
        $keyPath = (string) $doku['snap_private_key_path'];

        return Inertia::render('Admin/System', [
            'app' => [
                'env' => app()->environment(), 'debug' => (bool) config('app.debug'), 'url' => config('app.url'),
                'indexable' => (bool) config('site.indexable'), 'timezone' => config('app.timezone'),
                'php' => PHP_VERSION, 'laravel' => app()->version(),
            ],
            'payments' => [
                'online' => PaymentGateways::enabled(), 'mode' => $doku['mode'], 'production' => (bool) $doku['is_production'],
                'clientId' => filled($doku['client_id']), 'secret' => filled($doku['secret_key']) || filled($doku['snap_client_secret']),
                'qrisMerchant' => filled($doku['qris_merchant_id']), 'qrisTerminal' => filled($doku['qris_terminal_id']),
                'privateKey' => is_readable($keyPath), 'publicKey' => $this->publicKey($keyPath),
                'webhooks' => ['checkout' => url('/webhooks/doku'), 'qris' => url('/webhooks/doku-qris')],
                'recentFailures' => Payment::whereNotNull('last_error')->latest('updated_at')->limit(5)->get(['provider_order_id', 'provider', 'status', 'last_error', 'updated_at']),
            ],
            'erzap' => [
                'enabled' => (bool) config('services.erzap.enabled'), 'baseUrl' => config('services.erzap.base_url'), 'token' => filled(config('services.erzap.token')),
                'defaultOutlet' => config('services.erzap.default_outlet_id'), 'salesUser' => config('services.erzap.sales_user_id'),
                'missing' => $erzap->missingSettings(),
                'counts' => IntegrationSync::where('provider', 'erzap')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
                'lastSent' => IntegrationSync::where('provider', 'erzap')->max('synced_at'),
                'recentFailures' => IntegrationSync::where('provider', 'erzap')->whereIn('status', ['failed', 'needs_mapping', 'waiting_config'])
                    ->latest('updated_at')->limit(5)->get(['subject_id', 'status', 'attempts', 'last_error', 'updated_at']),
            ],
            'api' => ['tokens' => count(config('services.api.tokens', [])), 'base' => url('/api/v1')],
            'cron' => 'cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
            'tasks' => collect(self::TASKS)->map(fn ($task, $key) => ['key' => $key, 'label' => $task[1]])->values(),
            'log' => $this->logTail(),
            'queue' => ['failedJobs' => DB::table('failed_jobs')->count()],
        ]);
    }

    public function run(Request $request)
    {
        $data = $request->validate(['task' => ['required', Rule::in(array_keys(self::TASKS))]]);
        [$command, $label] = self::TASKS[$data['task']];
        Artisan::call($command);
        Audit::log('system.task_run', 'system', 0, ['task' => $data['task']]);
        $output = trim(Artisan::output());

        return back()->with('success', $label.': done.'.($output !== '' ? ' '.mb_substr(preg_replace('/\s+/', ' ', $output), 0, 300) : ''));
    }

    /** Creates the SNAP RSA key pair once; the private key stays in private storage. */
    public function generateKey()
    {
        $path = (string) config('services.doku.snap_private_key_path');
        if (is_readable($path)) {
            return back()->withErrors(['key' => 'A key already exists. Delete it on the server first if you really need a new one (the public key in DOKU must then be replaced too).']);
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            return back()->withErrors(['key' => 'This server could not generate an RSA key. Create it over SSH: openssl genrsa -out '.$path.' 2048']);
        }
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, $pem);
        @chmod($path, 0600);
        Audit::log('system.snap_key_generated', 'system', 0);

        return back()->with('success', 'Key pair created. Copy the public key below into the DOKU dashboard.');
    }

    private function publicKey(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }
        $key = openssl_pkey_get_private((string) file_get_contents($path));

        return $key ? (openssl_pkey_get_details($key)['key'] ?? null) : null;
    }

    /** Last lines of the newest log file, with long token-like strings masked. */
    private function logTail(int $lines = 60): array
    {
        $files = glob(storage_path('logs/*.log')) ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $file = $files[0] ?? null;
        if (! $file) {
            return ['file' => null, 'lines' => []];
        }
        $handle = fopen($file, 'r');
        fseek($handle, max(0, filesize($file) - 64 * 1024));
        $tail = array_slice(preg_split('/\R/', (string) stream_get_contents($handle)), -$lines);
        fclose($handle);

        return ['file' => basename($file), 'lines' => array_values(array_map(
            fn ($line) => mb_substr(preg_replace('/[A-Za-z0-9+\/=_-]{32,}/', '[hidden]', $line), 0, 400), array_filter($tail, fn ($line) => trim($line) !== '')
        ))];
    }
}

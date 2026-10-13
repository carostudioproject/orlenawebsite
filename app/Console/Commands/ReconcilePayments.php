<?php

namespace App\Console\Commands;

use App\Actions\Payments\ReconcilePayment;
use App\Models\Payment;
use App\Services\Doku\DokuException;
use Illuminate\Console\Command;

/**
 * Reads payment status back from DOKU so nothing depends on a single notification:
 * open links every CHECK_OPEN minutes (a lost callback is still found), expired ones are closed,
 * and links closed in the last CLOSED_DAYS days are looked at again every CHECK_CLOSED minutes (a late payment is recorded).
 */
class ReconcilePayments extends Command
{
    private const CHECK_OPEN = 10;

    private const CHECK_CLOSED = 60;

    private const CLOSED_DAYS = 2;

    /** Providers whose status DOKU can tell; manual and demo payments are never checked. */
    private const PROVIDERS = ['doku', 'doku_qris', 'doku_va'];

    protected $signature = 'payments:reconcile {--limit=50}';

    protected $description = 'Check open and recently closed DOKU payments and record their final status';

    public function handle(ReconcilePayment $reconcile): int
    {
        $due = fn ($query, int $minutes) => $query->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes($minutes)));
        $payments = Payment::whereIn('provider', self::PROVIDERS)
            ->where(fn ($query) => $query
                ->where(fn ($open) => $due($open->where('status', 'pending'), self::CHECK_OPEN))
                ->orWhere(fn ($closed) => $due($closed->whereIn('status', ['expired', 'cancelled'])->where('updated_at', '>=', now()->subDays(self::CLOSED_DAYS)), self::CHECK_CLOSED)))
            // Never checked first, then the longest unchecked.
            ->orderByRaw('checked_at IS NOT NULL')->orderBy('checked_at')->orderBy('id')
            ->limit((int) $this->option('limit'))->get();
        foreach ($payments as $payment) {
            try {
                $this->line($payment->provider_order_id.': '.$reconcile->handle($payment));
            } catch (DokuException $e) {
                $this->warn($payment->provider_order_id.': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Actions\Payments\ReconcilePayment;
use App\Models\Payment;
use App\Services\Doku\DokuException;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--limit=50}';

    protected $description = 'Check pending DOKU payments whose link has expired and record their final status';

    public function handle(ReconcilePayment $reconcile): int
    {
        $payments = Payment::where('status', 'pending')->where('expires_at', '<', now()->subMinutes(5))
            ->orderBy('expires_at')->limit((int) $this->option('limit'))->get();
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

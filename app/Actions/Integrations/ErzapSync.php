<?php

namespace App\Actions\Integrations;

use App\Models\IntegrationSync;
use App\Models\Order;
use App\Services\Erzap\ErzapClient;
use App\Services\Erzap\ErzapException;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Paid orders are sent to Erzap as transactions; paid orders later cancelled or refunded are voided there.
 * Queuing only writes a row (safe inside the payment transaction); the scheduler or a staff retry sends it.
 */
class ErzapSync
{
    /** Minutes to wait after each failed attempt. */
    private const BACKOFF = [5, 15, 60, 180, 720];

    public function __construct(private ErzapClient $erzap) {}

    public static function queue(Order $order, string $type): void
    {
        DB::table('integration_syncs')->insertOrIgnore([
            'provider' => 'erzap', 'type' => $type, 'subject_type' => 'orders', 'subject_id' => $order->id,
            'sync_key' => 'erzap:'.$type.':orders:'.$order->id, 'status' => 'pending', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Sends one sync. Returns its new status. */
    public function run(IntegrationSync $sync, ?int $actor = null): string
    {
        return DB::transaction(function () use ($sync, $actor) {
            $sync = IntegrationSync::lockForUpdate()->findOrFail($sync->id);
            if ($sync->status === 'synced') {
                return 'synced';
            }
            $order = Order::with('items.product', 'outlet', 'customer', 'payments')->find($sync->subject_id);
            if (! $order) {
                $sync->update(['status' => 'skipped', 'last_error' => 'Order not found.', 'next_attempt_at' => null]);

                return 'skipped';
            }
            // A void is only needed when the transaction reached Erzap.
            if ($sync->type === 'transaction.cancel' && ! IntegrationSync::where('sync_key', 'erzap:transaction.push:orders:'.$order->id)->where('status', 'synced')->exists()) {
                $sync->update(['status' => 'skipped', 'last_error' => 'The transaction was never sent to Erzap.', 'next_attempt_at' => null]);

                return 'skipped';
            }
            if (! $this->erzap->configured()) {
                $sync->update(['status' => 'waiting_config', 'last_error' => 'Erzap credentials and endpoints are not set yet.', 'next_attempt_at' => null]);

                return 'waiting_config';
            }
            if ($missing = $this->missingMapping($order)) {
                $sync->update(['status' => 'needs_mapping', 'last_error' => 'No Erzap ID yet for: '.implode(', ', $missing).'.', 'next_attempt_at' => null]);

                return 'needs_mapping';
            }
            $payload = $this->payload($order);
            try {
                $result = $sync->type === 'transaction.cancel' ? $this->erzap->cancelTransaction($payload) : $this->erzap->pushTransaction($payload);
            } catch (ErzapException $e) {
                if ($e->notConfigured) {
                    $sync->update(['status' => 'waiting_config', 'payload' => $payload, 'last_error' => 'Erzap credentials and endpoints are not set yet.', 'next_attempt_at' => null]);

                    return 'waiting_config';
                }
                $attempts = $sync->attempts + 1;
                $sync->update([
                    'status' => 'failed', 'attempts' => $attempts, 'payload' => $payload, 'last_error' => mb_substr($e->getMessage(), 0, 500),
                    'next_attempt_at' => $attempts < IntegrationSync::MAX_AUTO_ATTEMPTS ? now()->addMinutes(self::BACKOFF[$attempts - 1]) : null,
                ]);
                Audit::log('erzap.sync_failed', 'integration_syncs', $sync->id, ['attempts' => $attempts, 'error' => $sync->last_error], $actor);

                return 'failed';
            }
            $sync->update([
                'status' => 'synced', 'attempts' => $sync->attempts + 1, 'payload' => $payload, 'response' => $result['body'],
                'external_ref' => $result['reference'] !== '' ? mb_substr($result['reference'], 0, 120) : null, 'last_error' => null, 'next_attempt_at' => null, 'synced_at' => now(),
            ]);
            Audit::log('erzap.synced', 'integration_syncs', $sync->id, ['type' => $sync->type, 'order' => $order->order_code], $actor);

            return 'synced';
        });
    }

    /** Syncs the scheduler should try now: new rows, failures past their backoff, and rows waiting for config or mapping. */
    public function due(int $limit = 50)
    {
        return IntegrationSync::where('provider', 'erzap')->whereIn('status', IntegrationSync::RUNNABLE)
            ->where(fn ($q) => $q->where('status', '!=', 'failed')->orWhere(fn ($q) => $q->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', now())))
            ->orderBy('id')->limit($limit)->get();
    }

    private function missingMapping(Order $order): array
    {
        $missing = [];
        if (! $order->outlet?->erzap_outlet_id) {
            $missing[] = 'outlet '.$order->outlet_name_snapshot;
        }
        foreach ($order->items as $item) {
            if (! $item->product?->erzap_product_id && ! $item->product?->barcode) {
                $missing[] = 'product '.$item->product_name_snapshot.($item->variant_snapshot ? ' ('.$item->variant_snapshot.')' : '');
            }
        }

        return array_values(array_unique($missing));
    }

    /** Draft transaction format; align field names with the Erzap API documentation when it is received. */
    private function payload(Order $order): array
    {
        $payment = $order->payments->where('status', 'paid')->sortByDesc('attempt')->first();

        return [
            'reference' => $order->order_code,
            'outlet_id' => $order->outlet->erzap_outlet_id,
            'transaction_at' => ($payment?->paid_at ?? $order->updated_at)->setTimezone('Asia/Makassar')->toIso8601String(),
            'customer' => ['name' => $order->customer->name, 'phone' => $order->customer->whatsapp],
            'fulfillment' => ['method' => $order->fulfillment_method, 'date' => $order->requested_date->toDateString()],
            'items' => $order->items->map(fn ($item) => [
                'product_id' => $item->product?->erzap_product_id, 'variant_id' => $item->product?->erzap_variant_id, 'barcode' => $item->product?->barcode,
                'sku' => $item->sku_snapshot, 'name' => $item->product_name_snapshot, 'variant' => $item->variant_snapshot,
                'quantity' => $item->quantity, 'price' => $item->unit_price_snapshot, 'subtotal' => $item->subtotal,
            ])->values()->all(),
            'subtotal' => $order->subtotal, 'delivery_fee' => $order->delivery_fee ?? 0, 'total' => $order->total,
            'payment' => ['provider' => 'midtrans', 'method' => $payment?->payment_type, 'transaction_id' => $payment?->transaction_id, 'amount' => $payment?->amount],
        ];
    }
}

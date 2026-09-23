<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSync;
use App\Models\Order;
use App\Models\Product;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Calls from Erzap (Bearer ERZAP_WEBHOOK_TOKEN). The payload shape is our draft until Erzap's documentation
 * arrives; the endpoints and storage stay the same, only the field mapping here may change.
 */
class ErzapWebhookController extends Controller
{
    /** Reference stock per product. Stored for staff only; never validates or reserves a PO. */
    public function stock(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.erzap_product_id' => ['nullable', 'required_without:items.*.barcode', 'string', 'max:80'],
            'items.*.barcode' => ['nullable', 'string', 'max:80'],
            'items.*.stock' => ['required', 'integer', 'min:-1000000', 'max:1000000'],
        ]);
        $updated = 0;
        $unknown = [];
        DB::transaction(function () use ($data, &$updated, &$unknown) {
            foreach ($data['items'] as $row) {
                $query = Product::query()->when($row['erzap_product_id'] ?? null, fn ($q, $id) => $q->where('erzap_product_id', $id), fn ($q) => $q->where('barcode', $row['barcode']));
                $count = $query->update(['reference_stock' => $row['stock'], 'reference_stock_at' => now()]);
                $updated += $count;
                if (! $count) {
                    $unknown[] = $row['erzap_product_id'] ?? $row['barcode'];
                }
            }
        });

        return ['updated' => $updated, 'unmatched' => array_values(array_unique($unknown))];
    }

    /** Erzap reports the result of a transaction we sent. */
    public function syncStatus(Request $request)
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:40'], 'type' => ['nullable', 'in:transaction.push,transaction.cancel'],
            'status' => ['required', 'in:success,failed'], 'erzap_id' => ['nullable', 'string', 'max:120'], 'message' => ['nullable', 'string', 'max:500'],
        ]);
        $order = Order::where('order_code', $data['reference'])->first();
        $sync = $order ? IntegrationSync::where('sync_key', 'erzap:'.($data['type'] ?? 'transaction.push').':orders:'.$order->id)->first() : null;
        if (! $sync) {
            return response()->json(['message' => 'Transaksi tidak ditemukan.'], 404);
        }
        $sync->update($data['status'] === 'success'
            ? ['status' => 'synced', 'external_ref' => $data['erzap_id'] ?? $sync->external_ref, 'last_error' => null, 'next_attempt_at' => null, 'synced_at' => $sync->synced_at ?? now()]
            : ['status' => 'failed', 'last_error' => mb_substr('Erzap: '.($data['message'] ?? 'ditolak'), 0, 500), 'next_attempt_at' => null]);
        Audit::log('erzap.callback', 'integration_syncs', $sync->id, ['status' => $data['status'], 'erzap_id' => $data['erzap_id'] ?? null], null);

        return ['status' => $sync->status];
    }
}

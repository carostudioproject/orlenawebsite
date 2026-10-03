<?php

namespace App\Actions\Integrations;

use App\Models\IntegrationSync;
use App\Models\Order;
use App\Models\User;
use App\Services\Erzap\ErzapClient;
use App\Services\Erzap\ErzapException;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Paid orders are sent to Erzap as sales orders (OLZAP simpan_pesanan_penjualan).
 * Queuing only writes a row (safe inside the payment transaction); the scheduler or a staff retry sends it.
 */
class ErzapSync
{
    public const TYPE = 'transaction.push';

    /** Minutes to wait after each failed attempt. */
    private const BACKOFF = [5, 15, 60, 180, 720];

    public function __construct(private ErzapClient $erzap) {}

    public static function queue(Order $order): void
    {
        DB::table('integration_syncs')->insertOrIgnore([
            'provider' => 'erzap', 'type' => self::TYPE, 'subject_type' => 'orders', 'subject_id' => $order->id,
            'sync_key' => 'erzap:'.self::TYPE.':orders:'.$order->id, 'status' => 'pending', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Sends the order's queued sync right away (after a payment). Returns the sync status, or null when nothing was queued.
     * If it cannot be sent yet, the 5-minute schedule keeps retrying.
     */
    public function sendNow(Order $order, ?int $actor = null): ?string
    {
        $sync = IntegrationSync::where('provider', 'erzap')->where('subject_type', 'orders')->where('subject_id', $order->id)
            ->where('status', 'pending')->latest('id')->first();

        return $sync ? $this->run($sync, $actor) : null;
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
            if (! $order || $order->payment_status !== 'paid') {
                $sync->update(['status' => 'skipped', 'last_error' => $order ? 'The order is no longer paid.' : 'Order not found.', 'next_attempt_at' => null]);

                return 'skipped';
            }
            if (! $this->erzap->configured()) {
                $sync->update(['status' => 'waiting_config', 'last_error' => 'Missing Erzap settings: '.implode(', ', $this->erzap->missingSettings()).'.', 'next_attempt_at' => null]);

                return 'waiting_config';
            }
            if ($missing = $this->missingMapping($order)) {
                $sync->update(['status' => 'needs_mapping', 'last_error' => 'Missing in Mapping: '.implode(', ', $missing).'.', 'next_attempt_at' => null]);

                return 'needs_mapping';
            }
            $cart = $this->cart($order);
            try {
                $result = $this->erzap->sendOrder($cart);
            } catch (ErzapException $e) {
                if ($e->notConfigured) {
                    $sync->update(['status' => 'waiting_config', 'payload' => $cart, 'last_error' => 'Erzap settings are not complete.', 'next_attempt_at' => null]);

                    return 'waiting_config';
                }
                $attempts = $sync->attempts + 1;
                $sync->update([
                    'status' => 'failed', 'attempts' => $attempts, 'payload' => $cart, 'last_error' => mb_substr($e->getMessage(), 0, 500),
                    'next_attempt_at' => $attempts < IntegrationSync::MAX_AUTO_ATTEMPTS ? now()->addMinutes(self::BACKOFF[$attempts - 1]) : null,
                ]);
                Audit::log('erzap.sync_failed', 'integration_syncs', $sync->id, ['attempts' => $attempts, 'error' => $sync->last_error], $actor);

                return 'failed';
            }
            $sync->update([
                'status' => 'synced', 'attempts' => $sync->attempts + 1, 'payload' => $cart, 'response' => $result['body'],
                'external_ref' => $order->order_code, 'last_error' => null, 'next_attempt_at' => null, 'synced_at' => now(),
            ]);
            Audit::log('erzap.synced', 'integration_syncs', $sync->id, ['order' => $order->order_code], $actor);

            return 'synced';
        });
    }

    /** Syncs the scheduler should try now: new rows, failures past their backoff, and rows waiting for config or mapping. */
    public function due(int $limit = 50)
    {
        return IntegrationSync::where('provider', 'erzap')->where('type', self::TYPE)->whereIn('status', IntegrationSync::RUNNABLE)
            ->where(fn ($q) => $q->where('status', '!=', 'failed')->orWhere(fn ($q) => $q->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', now())))
            ->orderBy('id')->limit($limit)->get();
    }

    /** Erzap matches items by barcode and needs a receiving outlet. */
    private function missingMapping(Order $order): array
    {
        $missing = [];
        if (! $this->outletId($order)) {
            $missing[] = 'Erzap outlet ID for '.$order->outlet_name_snapshot;
        }
        foreach ($order->items as $item) {
            if (blank($item->product?->barcode)) {
                $missing[] = 'barcode for '.$item->product_name_snapshot.($item->variant_snapshot ? ' ('.$item->variant_snapshot.')' : '');
            }
        }

        return array_values(array_unique($missing));
    }

    private function outletId(Order $order): ?int
    {
        $id = $order->outlet?->erzap_outlet_id ?: config('services.erzap.default_outlet_id');

        return filled($id) && is_numeric($id) ? (int) $id : null;
    }

    /** OLZAP "shopping_carts" (token added by the client). Amounts use Erzap's decimal-string style, e.g. "120000.0". */
    /** Erzap sales user: the staff member who confirmed the order, if their Erzap ID is set; otherwise ERZAP_SALES_USER_ID. */
    private function salesUserId(Order $order): int
    {
        $confirmedBy = DB::table('order_status_histories')->where('order_id', $order->id)->where('to_status', 'confirmed')->latest('id')->value('actor_id');
        $own = $confirmedBy ? User::whereKey($confirmedBy)->value('erzap_sales_user_id') : null;

        return (int) ($own ?: config('services.erzap.sales_user_id'));
    }

    private function cart(Order $order): array
    {
        $money = fn (?int $amount) => number_format((float) ($amount ?? 0), 1, '.', '');
        $payment = $order->payments->where('status', 'paid')->sortByDesc('attempt')->first();
        $delivery = $order->fulfillment_method === 'delivery';
        $address = $delivery ? (string) $order->delivery_address : '';
        $schedule = $order->requested_date->toDateString().($order->requested_time ? ' '.substr($order->requested_time, 0, 5) : '').' WITA';
        $note = implode(' | ', array_filter([
            'Website order '.$order->order_code,
            ($delivery ? 'Delivery (Gojek/Grab)' : 'Pickup '.$order->outlet_name_snapshot).', '.$schedule,
            'Paid via '.($payment?->provider === 'manual' ? 'manual payment' : 'DOKU').($payment?->payment_type ? ' ('.$payment->payment_type.')' : ''),
            $order->customer_note ? 'Note: '.$order->customer_note : null,
            $order->card_message ? 'Greeting card: '.$order->card_message : null,
        ]));

        return [
            'alamat' => $address, 'alamat_pengiriman' => $address,
            'biaya_admin' => 0.0,
            'created_at' => $order->created_at->setTimezone('Asia/Makassar')->toIso8601String(),
            'email' => (string) ($order->customer->email ?? ''),
            'is_drop_ship' => false,
            // Our Order Code, or null so Erzap numbers the order itself (ERZAP_SEND_ORDER_CODE=false). The code is always in the note.
            'kode' => config('services.erzap.send_order_code', true) ? $order->order_code : null,
            'kode_pos' => '', 'kode_pos_pengiriman' => '',
            'konfirmasi_dari_bank' => $payment?->provider === 'manual' ? 'Manual' : 'DOKU',
            'konfirmasi_nama_akun' => null, 'konfirmasi_no_rekening_akun' => null,
            'konfirmasi_tanggal_bayar' => ($payment?->paid_at ?? $order->updated_at)->setTimezone('Asia/Makassar')->toIso8601String(),
            'nama' => $order->customer->name, 'nama_penerima_pengiriman' => $order->customer->name,
            'nominal_voucher' => '0.0',
            'ongkos_kirim' => $delivery ? $money($order->delivery_fee) : null,
            'telepon' => $order->customer->whatsapp, 'telepon_pengiriman' => $order->customer->whatsapp,
            'tempat_penjemputan' => $delivery ? null : $order->outlet_name_snapshot,
            'total_pembayaran' => $money($payment?->amount ?? $order->total),
            'total_pesanan' => $money($order->subtotal),
            'pelanggan_kecamatan' => '', 'pelanggan_kota' => '', 'pelanggan_country' => '', 'pelanggan_provinsi' => '',
            'pelanggan_kecamatan_pengiriman' => '', 'pelanggan_kota_pengiriman' => '', 'pelanggan_country_pengiriman' => '', 'pelanggan_provinsi_pengiriman' => '',
            'pelanggan_payment_channel' => strtoupper($payment?->provider === 'manual' ? (string) $payment->payment_type : 'DOKU'.($payment?->payment_type ? '-'.$payment->payment_type : '')),
            'pelanggan_ekspedisi' => $delivery ? 'GOJEK/GRAB' : 'PICKUP',
            'pelanggan_kode' => null,
            'idoutlet_penerima_pesanan_online_erzap' => $this->outletId($order),
            'iduser_sales_penerima_pesanan_online_erzap' => $this->salesUserId($order),
            'informasi_tambahan_text' => mb_substr($note, 0, 500),
            'kode_voucher_text' => '',
            'shopping_cart_details' => $order->items->map(fn ($item) => [
                'harga_satuan' => (float) $item->unit_price_snapshot, 'jumlah' => $item->quantity, 'tgl_check_in' => null, 'barcode_produk' => $item->product->barcode,
            ])->values()->all(),
        ];
    }
}

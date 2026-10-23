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

    /** Rows waiting for settings or mapping are looked at again after this long (saving the mapping requeues them at once). */
    private const PARK_MINUTES = 30;

    /** How long a send may take before another run may pick the row up again (well above the HTTP timeout). */
    private const CLAIM_SECONDS = 120;

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
        // 1. Claim the row in a short transaction: checks and the cart are prepared, the row becomes "sending".
        $claim = DB::transaction(function () use ($sync) {
            $sync = IntegrationSync::lockForUpdate()->findOrFail($sync->id);
            if ($sync->status === 'synced') {
                return 'synced';
            }
            // Another process is sending it right now (its claim has not expired).
            if ($sync->status === 'sending' && $sync->next_attempt_at?->isFuture()) {
                return 'sending';
            }
            $order = Order::with('items.product', 'outlet', 'customer', 'payments')->find($sync->subject_id);
            // A cancelled order is never sent as a sale, even when it was paid (the refund is handled outside Erzap).
            if (! $order || $order->payment_status !== 'paid' || $order->order_status === 'cancelled') {
                $sync->update(['status' => 'skipped', 'last_error' => match (true) {
                    ! $order => 'Order not found.',
                    $order->order_status === 'cancelled' => 'The order was cancelled.',
                    default => 'The order is no longer paid.',
                }, 'next_attempt_at' => null]);

                return 'skipped';
            }
            if (! $this->erzap->configured()) {
                $sync->update(['status' => 'waiting_config', 'last_error' => 'Missing Erzap settings: '.implode(', ', $this->erzap->missingSettings()).'.', 'next_attempt_at' => now()->addMinutes(self::PARK_MINUTES)]);

                return 'waiting_config';
            }
            if ($missing = $this->missingMapping($order)) {
                $sync->update(['status' => 'needs_mapping', 'last_error' => 'Missing in Mapping: '.implode(', ', $missing).'.', 'next_attempt_at' => now()->addMinutes(self::PARK_MINUTES)]);

                return 'needs_mapping';
            }
            $cart = $this->cart($order);
            // The claim expires on its own, so a process that dies mid-request does not block the row forever.
            $sync->update(['status' => 'sending', 'payload' => $cart, 'next_attempt_at' => now()->addSeconds(self::CLAIM_SECONDS)]);

            return [$cart, $order->order_code];
        }, 3);
        if (is_string($claim)) {
            return $claim;
        }
        [$cart, $code] = $claim;

        // 2. The request to Erzap runs outside any database transaction, so no row stays locked while Erzap answers.
        try {
            $result = $this->erzap->sendOrder($cart);
            $error = null;
        } catch (ErzapException $e) {
            [$result, $error] = [null, $e];
        }

        // 3. Record the outcome, unless something else settled the row meanwhile (e.g. Erzap's own status 5).
        return DB::transaction(function () use ($sync, $actor, $cart, $code, $result, $error) {
            $sync = IntegrationSync::lockForUpdate()->findOrFail($sync->id);
            if ($sync->status !== 'sending') {
                return $sync->status;
            }
            if ($error?->notConfigured) {
                $sync->update(['status' => 'waiting_config', 'last_error' => 'Erzap settings are not complete.', 'next_attempt_at' => now()->addMinutes(self::PARK_MINUTES)]);

                return 'waiting_config';
            }
            if ($error) {
                $attempts = $sync->attempts + 1;
                $sync->update([
                    'status' => 'failed', 'attempts' => $attempts, 'last_error' => mb_substr($error->getMessage(), 0, 500),
                    'next_attempt_at' => $attempts < IntegrationSync::MAX_AUTO_ATTEMPTS ? now()->addMinutes(self::BACKOFF[$attempts - 1]) : null,
                ]);
                Audit::log('erzap.sync_failed', 'integration_syncs', $sync->id, ['attempts' => $attempts, 'error' => $sync->last_error], $actor);

                return 'failed';
            }
            $sync->update([
                'status' => 'synced', 'attempts' => $sync->attempts + 1, 'payload' => $cart, 'response' => $result['body'],
                'external_ref' => $code, 'last_error' => null, 'next_attempt_at' => null, 'synced_at' => now(),
            ]);
            Audit::log('erzap.synced', 'integration_syncs', $sync->id, ['order' => $code], $actor);

            return 'synced';
        }, 3);
    }

    /** Syncs the scheduler should try now: new rows, then failed or parked rows whose next attempt is due. */
    public function due(int $limit = 50)
    {
        return IntegrationSync::where('provider', 'erzap')->where('type', self::TYPE)->whereIn('status', [...IntegrationSync::RUNNABLE, 'sending'])
            // New rows run at once; failed, parked (settings or mapping missing) and abandoned "sending" rows only when their time comes.
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', now())))
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

    /** Erzap sales user: the staff member who confirmed the order, if their Erzap ID is set; otherwise ERZAP_SALES_USER_ID. */
    private function salesUserId(Order $order): int
    {
        $confirmedBy = DB::table('order_status_histories')->where('order_id', $order->id)->where('to_status', 'confirmed')->latest('id')->value('actor_id');
        $own = $confirmedBy ? User::whereKey($confirmedBy)->value('erzap_sales_user_id') : null;

        return (int) ($own ?: config('services.erzap.sales_user_id'));
    }

    /** The same order for Erzap's check_pesanan pull: always with our Order Code, Erzap's duplicate key and the code it reports back. */
    public function pullCart(Order $order): array
    {
        return [...$this->cart($order), 'kode' => $order->order_code];
    }

    /**
     * OLZAP "shopping_carts" as in the Erzap API v1.3 document (token added by the client).
     * Numbers are decimal strings ("120000.0"), the payment date is a date only ("2026-08-27").
     */
    private function cart(Order $order): array
    {
        $money = fn (int|float|null $amount) => number_format((float) ($amount ?? 0), 1, '.', '');
        $payment = $order->payments->where('status', 'paid')->sortByDesc('attempt')->first();
        $delivery = $order->fulfillment_method === 'delivery';
        $address = $delivery ? (string) $order->delivery_address : '';
        $schedule = $order->requested_date->toDateString().($order->requested_time ? ' '.substr($order->requested_time, 0, 5) : '').' WITA';
        // Three short lines, as Orlena reads them in Erzap: order, where and when, how it was paid.
        // Address, recipient, location, notes and card texts stay on the website (order page, Rekap CK).
        $note = implode("\n", [
            'Website Order '.$order->order_code,
            ($delivery ? 'Delivery (Gojek/Grab) from '.$order->outlet_name_snapshot : 'Pickup '.$order->outlet_name_snapshot).', '.$schedule,
            'Paid via '.($payment?->provider === 'manual' ? 'manual payment' : 'DOKU').($payment?->payment_type ? ' ('.$payment->payment_type.')' : ''),
        ]);

        return [
            'alamat' => $address, 'alamat_pengiriman' => $address,
            'biaya_admin' => '0.0',
            'created_at' => $order->created_at->setTimezone('Asia/Makassar')->toIso8601String(),
            'email' => (string) ($order->customer->email ?? ''),
            'is_drop_ship' => false,
            // Our Order Code, or null so Erzap numbers the order itself (ERZAP_SEND_ORDER_CODE=false). The code is always in the note.
            'kode' => config('services.erzap.send_order_code', true) ? $order->order_code : null,
            'kode_pos' => '', 'kode_pos_pengiriman' => '',
            'konfirmasi_dari_bank' => $payment?->provider === 'manual' ? 'Manual' : 'DOKU',
            'konfirmasi_nama_akun' => null, 'konfirmasi_no_rekening_akun' => null,
            'konfirmasi_tanggal_bayar' => ($payment?->paid_at ?? $order->updated_at)->setTimezone('Asia/Makassar')->toDateString(),
            'nama' => $order->customer->name, 'nama_penerima_pengiriman' => $order->customer->name,
            'nominal_voucher' => '0.0',
            'ongkos_kirim' => $money($delivery ? $order->delivery_fee : 0), 'nominal_gratis_ongkir' => '0.0',
            'telepon' => $order->customer->whatsapp, 'telepon_pengiriman' => $order->customer->whatsapp,
            'tempat_penjemputan' => $delivery ? null : $order->outlet_name_snapshot,
            'total_pembayaran' => $money($order->total),
            'total_pesanan' => $money($order->subtotal),
            'pelanggan_kecamatan' => '', 'pelanggan_kota' => '', 'pelanggan_country' => '', 'pelanggan_provinsi' => '',
            'pelanggan_kecamatan_pengiriman' => '', 'pelanggan_kota_pengiriman' => '', 'pelanggan_country_pengiriman' => '', 'pelanggan_provinsi_pengiriman' => '',
            'pelanggan_payment_channel' => strtoupper($payment?->provider === 'manual' ? (string) $payment->payment_type : 'DOKU'.($payment?->payment_type ? '-'.$payment->payment_type : '')),
            'pelanggan_ekspedisi' => $delivery ? 'GOJEK/GRAB' : 'PICKUP',
            'pelanggan_kode' => null,
            // v1.3 names the receiving outlet by code; the two ID fields of the earlier payload are still sent alongside.
            'kode_outlet' => config('services.erzap.outlet_code', ''),
            'idoutlet_penerima_pesanan_online_erzap' => $this->outletId($order),
            'iduser_sales_penerima_pesanan_online_erzap' => $this->salesUserId($order),
            'informasi_tambahan_text' => mb_substr($note, 0, 500),
            'kode_voucher_text' => '',
            // Hampers/box lines and their chosen contents are separate Erzap products; the same barcode at the same price is one line.
            'shopping_cart_details' => $order->items->groupBy(fn ($item) => $item->product->barcode.'|'.$item->unit_price_snapshot)
                ->map(fn ($lines) => [
                    'harga_satuan' => $money($lines->first()->unit_price_snapshot), 'jumlah' => $money($lines->sum('quantity')), 'tgl_check_in' => null,
                    'barcode_produk' => $lines->first()->product->barcode,
                ])->values()->all(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Erzap\ErzapInbound;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * URLs Erzap calls on the website (Erzap API v1.2, section 2), under /web_service/.../new.
 * Every call is checked by VerifyErzapToken. Answers are always HTTP 200; failures are in the JSON status.
 */
class ErzapWebServiceController extends Controller
{
    public function __construct(private ErzapInbound $inbound) {}

    /** 2.1 import_produk_json */
    public function products(Request $request)
    {
        [$created, $updated, $notes] = $this->inbound->products($this->items($request));
        $this->seen('products', compact('created', 'updated'));

        return response()->json(['olzap' => ['status' => 1, 'notice' => implode('; ', array_slice(array_unique($notes), 0, 10))]]);
    }

    /** 2.2 sinkronisasi_stok: accepted, but the website does not track stock. */
    public function stock(Request $request)
    {
        $this->seen('stock', ['items' => count($this->items($request))]);

        return $this->ok();
    }

    /** 2.3 sinkronisasi_harga */
    public function prices(Request $request)
    {
        $this->seen('prices', ['changed' => $this->inbound->prices($this->items($request))]);

        return $this->ok();
    }

    /** 2.4 check_pesanan: paid orders not yet saved in Erzap (max 20, oldest first). */
    public function orders()
    {
        $carts = $this->inbound->pendingOrders();
        $this->seen('orders', ['orders' => count($carts)]);

        return response()->json(['notice' => '', 'status' => 1, 'data' => ['shopping_carts' => $carts]]);
    }

    /** 2.5 update_status_pesanan: status 5 means Erzap saved the sales order. */
    public function orderStatus(Request $request)
    {
        $payload = $this->payload($request);
        $code = (string) ($payload['kode_shopping_cart'] ?? '');
        $status = (string) ($payload['update_status_shopping_cart'] ?? '');
        $this->seen('order_status', ['code' => mb_substr($code, 0, 40), 'status' => $status]);
        if ($status !== '5') {
            return response()->json(['notice' => 'Status '.mb_substr($status, 0, 10).' tidak dipakai', 'status' => 1]);
        }

        return match ($this->inbound->markSaved($code)) {
            'saved' => response()->json(['notice' => 'Status pesanan sukses diupdate', 'status' => 1]),
            'not_queued' => response()->json(['notice' => 'Pesanan '.mb_substr($code, 0, 40).' belum dibayar atau tidak dikirim ke ERZAP', 'status' => 0]),
            default => response()->json(['notice' => 'Data shopping cart dengan kode '.mb_substr($code, 0, 40).' tidak ditemukan', 'status' => 0]),
        };
    }

    /** 2.6 sinkronisasi_gambar: multipart with an erzap_json field and file_<ref> parts. */
    public function images(Request $request)
    {
        $meta = json_decode((string) $request->input('erzap_json'), true);
        if ($request->isJson() || ! is_array($meta)) {
            return response()->json(['olzap' => ['status' => '0', 'msg' => 'Sinkron gambar hanya menerima multipart/form-data (field erzap_json + part file_<ref>).']]);
        }
        $stored = $this->inbound->images($this->list($meta['erzap']['produks'] ?? []), fn (string $field) => $request->file($field));
        $this->seen('images', ['stored' => $stored]);

        return $this->ok();
    }

    private function ok()
    {
        return response()->json(['olzap' => ['status' => 'sukses', 'msg' => '']]);
    }

    private function items(Request $request): array
    {
        return $this->list($this->payload($request)['erzap']['produks'] ?? []);
    }

    private function list(mixed $items): array
    {
        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /** JSON body; also tolerates the "key"=>value notation used in Erzap's document. */
    private function payload(Request $request): array
    {
        $data = $request->json()->all();
        if (! $data) {
            $data = json_decode((string) preg_replace('/"\s*=>\s*/', '":', $request->getContent()), true);
        }

        return is_array($data) ? $data : [];
    }

    /** Last call per endpoint, shown in Developer tools. */
    private function seen(string $endpoint, array $result): void
    {
        Cache::forever('erzap-inbound:'.$endpoint, ['at' => now()->toIso8601String(), ...$result]);
    }
}

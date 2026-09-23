<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Integrations\ErzapSync;
use App\Http\Controllers\Controller;
use App\Models\IntegrationSync;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Services\Erzap\ErzapClient;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Erzap: connection status, outlet/product mapping, and the sync log with manual retry. */
class IntegrationController extends Controller
{
    public function index(Request $request, ErzapClient $erzap)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(IntegrationSync::LABELS))], 'search' => ['nullable', 'string', 'max:60'], 'page' => ['nullable', 'integer', 'min:1']]);
        $syncs = IntegrationSync::where('provider', 'erzap')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereIn('subject_id', Order::where('order_code', 'like', '%'.$search.'%')->select('id')))
            ->latest('id')->paginate(10)->withQueryString();
        $codes = Order::whereIn('id', $syncs->getCollection()->pluck('subject_id'))->pluck('order_code', 'id');
        $syncs->getCollection()->transform(fn (IntegrationSync $sync) => [
            ...$sync->only('id', 'type', 'status', 'attempts', 'last_error', 'external_ref', 'subject_id'),
            'order_code' => $codes[$sync->subject_id] ?? null, 'created_at' => $sync->created_at, 'synced_at' => $sync->synced_at, 'next_attempt_at' => $sync->next_attempt_at,
        ]);

        return Inertia::render('Admin/Integrations/Index', [
            'configured' => $erzap->configured(), 'syncs' => $syncs, 'filters' => $filters, 'labels' => IntegrationSync::LABELS,
            'counts' => IntegrationSync::where('provider', 'erzap')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'mapping' => [
                'outlets' => ['mapped' => Outlet::whereNotNull('erzap_outlet_id')->count(), 'total' => Outlet::count()],
                'products' => ['mapped' => Product::where(fn ($q) => $q->whereNotNull('erzap_product_id')->orWhereNotNull('barcode'))->count(), 'total' => Product::count()],
            ],
        ]);
    }

    public function mapping(Request $request)
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'unmapped' => ['nullable', 'in:1'], 'page' => ['nullable', 'integer', 'min:1']]);
        $products = Product::with('category:id,name')
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%')->orWhere('barcode', 'like', '%'.$search.'%')))
            ->when($filters['unmapped'] ?? null, fn ($q) => $q->whereNull('erzap_product_id')->whereNull('barcode'))
            ->orderBy('name')->orderBy('variant')->paginate(10, ['id', 'category_id', 'name', 'variant', 'sku', 'is_active', 'erzap_product_id', 'erzap_variant_id', 'barcode', 'reference_stock', 'reference_stock_at'])->withQueryString();

        return Inertia::render('Admin/Integrations/Mapping', [
            'outlets' => Outlet::orderBy('position')->orderBy('name')->get(['id', 'name', 'code', 'erzap_outlet_id']),
            'products' => $products, 'filters' => $filters,
        ]);
    }

    public function updateMapping(Request $request)
    {
        $data = $request->validate([
            'outlets' => ['sometimes', 'array', 'max:200'],
            'outlets.*.id' => ['required', 'integer', 'distinct', 'exists:outlets,id'],
            'outlets.*.erzap_outlet_id' => ['nullable', 'string', 'max:80'],
            'products' => ['sometimes', 'array', 'max:50'],
            'products.*.id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'products.*.erzap_product_id' => ['nullable', 'string', 'max:80'],
            'products.*.erzap_variant_id' => ['nullable', 'string', 'max:80'],
            'products.*.barcode' => ['nullable', 'string', 'max:80'],
        ]);
        DB::transaction(function () use ($data) {
            foreach ($data['outlets'] ?? [] as $row) {
                $outlet = Outlet::find($row['id']);
                $outlet->forceFill(['erzap_outlet_id' => $row['erzap_outlet_id'] ?? null]);
                if ($outlet->isDirty()) {
                    $outlet->save();
                    Audit::record('erzap.mapping_updated', $outlet, ['erzap_outlet_id' => $outlet->erzap_outlet_id]);
                }
            }
            foreach ($data['products'] ?? [] as $row) {
                $product = Product::find($row['id']);
                $product->fill(['erzap_product_id' => $row['erzap_product_id'] ?? null, 'erzap_variant_id' => $row['erzap_variant_id'] ?? null, 'barcode' => $row['barcode'] ?? null]);
                if ($product->isDirty()) {
                    Audit::record('erzap.mapping_updated', $product, $product->getDirty());
                    $product->save();
                }
            }
            // Syncs held back for missing mapping are tried again on the next scheduler run.
            IntegrationSync::where('provider', 'erzap')->where('status', 'needs_mapping')->update(['status' => 'pending']);
        });

        return back()->with('success', 'Erzap mapping saved.');
    }

    public function retry(Request $request, IntegrationSync $sync, ErzapSync $erzap)
    {
        abort_unless($sync->provider === 'erzap', 404);
        $status = $erzap->run($sync, $request->user()->id);
        $messages = [
            'synced' => 'Sent to Erzap.', 'waiting_config' => 'Not sent: Erzap credentials and endpoints are not set yet.',
            'needs_mapping' => 'Not sent: complete the Erzap mapping first.', 'skipped' => 'Nothing needed to be sent.',
        ];

        return $status === 'failed' ? back()->withErrors(['sync' => 'Could not send to Erzap: '.$sync->fresh()->last_error]) : back()->with('success', $messages[$status]);
    }
}

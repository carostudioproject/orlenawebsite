<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogRequest;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Support\Audit;
use App\Support\ContentImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CatalogController extends Controller
{
    private const MODELS = ['products' => Product::class, 'outlets' => Outlet::class, 'categories' => Category::class];

    private const FILTER_RULES = ['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive'], 'type' => ['nullable', 'in:regular,hampers']];

    public function index(Request $request)
    {
        $resource = $request->route('resource');
        $filters = $request->validate([...self::FILTER_RULES, 'page' => ['nullable', 'integer', 'min:1']]);
        $query = $this->filtered($resource, $filters);
        if ($resource === 'products') {
            $query->with('category');
        }
        // Categories list in order-form order; outlets in website order.
        if ($resource === 'categories') {
            $query->orderBy('order_position');
        } elseif ($resource === 'outlets') {
            $query->orderBy('position');
        }

        return Inertia::render('Admin/Catalog/Index', [
            'resource' => $resource, 'records' => $query->orderBy('name')->paginate(10)->withQueryString(), 'filters' => $filters,
            // Ids of inactive products without a price in these filters, so bulk activation can say what will be skipped.
            'unpriced' => $resource === 'products' ? $this->filtered($resource, $filters)->where('is_active', false)->whereNull('price')->pluck('id') : [],
        ]);
    }

    public function create(Request $request)
    {
        return $this->form($request);
    }

    public function edit(Request $request)
    {
        return $this->form($request, $request->route('id'));
    }

    private function form(Request $request, ?string $id = null)
    {
        $resource = $request->route('resource');
        $record = $id ? self::MODELS[$resource]::findOrFail($id) : null;
        if ($record instanceof Product) {
            $record->load('outletPrices');
        }

        return Inertia::render('Admin/Catalog/Form', [
            'resource' => $resource, 'record' => $record,
            // Opened from the Hampers list, or editing a hamper: the form, title, and menu follow Hampers.
            'hamperMode' => $resource === 'products' && ($record ? $record->is_hamper : $request->query('type') === 'hampers'),
            'categories' => $resource === 'products' ? Category::orderBy('name')->get(['id', 'name']) : [],
            'outlets' => $resource === 'products' ? Outlet::orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function store(CatalogRequest $request)
    {
        return $this->save($request);
    }

    public function update(CatalogRequest $request)
    {
        return $this->save($request, $request->route('id'));
    }

    private function save(CatalogRequest $request, ?string $id = null)
    {
        $resource = $request->route('resource');
        $record = DB::transaction(function () use ($request, $id, $resource) {
            $record = $id ? self::MODELS[$resource]::lockForUpdate()->findOrFail($id) : new (self::MODELS[$resource]);
            $data = $request->validated();
            $prices = $data['outlet_prices'] ?? [];
            unset($data['outlet_prices'], $data['upload']);
            if ($resource === 'products' && empty($data['is_hamper'])) {
                $data['hamper_contents'] = null;
            }
            if ($request->hasFile('upload')) {
                $data['image'] = ContentImage::resolve($request->file('upload'), null);
            }
            $record->fill($data);
            $changes = $record->getDirty();
            $record->save();
            // Only one outlet ships delivery orders.
            if ($record instanceof Outlet && $record->is_delivery_hub) {
                Outlet::whereKeyNot($record->id)->where('is_delivery_hub', true)->update(['is_delivery_hub' => false]);
            }
            if ($record instanceof Product) {
                $record->outletPrices()->delete();
                $record->outletPrices()->createMany($prices);
                $changes['outlet_prices'] = $prices;
            }
            Audit::record($id ? 'catalog.updated' : 'catalog.created', $record, $changes);

            return $record;
        });

        return redirect('/admin/'.$resource.($record instanceof Product && $record->is_hamper ? '?type=hampers' : ''))->with('success', 'Saved.');
    }

    /** Quick activate/deactivate from the list for products and outlets. */
    public function toggle(Request $request)
    {
        $resource = $request->route('resource');
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $record = DB::transaction(function () use ($resource, $request, $data) {
            $record = self::MODELS[$resource]::lockForUpdate()->findOrFail($request->route('id'));
            if ($record instanceof Product && $data['is_active'] && ! $record->price) {
                throw ValidationException::withMessages(['is_active' => 'Set a base price for '.$record->name.' before activating it.']);
            }
            $record->update(['is_active' => $data['is_active']]);
            Audit::record('catalog.'.($data['is_active'] ? 'activated' : 'deactivated'), $record, ['is_active' => $data['is_active']]);

            return $record;
        });

        $label = $record instanceof Product ? $record->label() : $record->name;

        return back()->with('success', $label.' '.($record->is_active ? 'diaktifkan.' : 'dinonaktifkan.'));
    }

    /**
     * Activate or deactivate many rows at once: the selected ids, or every row matching the list filters.
     * Products without a base price are never activated; they are counted as skipped.
     */
    public function bulkStatus(Request $request)
    {
        $resource = $request->route('resource');
        $data = $request->validate([
            'is_active' => ['required', 'boolean'], 'all' => ['boolean'],
            'ids' => ['exclude_if:all,true', 'required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer', 'min:1', 'distinct'],
            'filters' => ['array'], ...collect(self::FILTER_RULES)->mapWithKeys(fn ($rule, $key) => ['filters.'.$key => $rule])->all(),
        ], ['ids.required' => 'Select at least one row.']);
        $active = (bool) $data['is_active'];

        [$changed, $skipped] = DB::transaction(function () use ($resource, $data, $active) {
            $query = ($data['all'] ?? false) ? $this->filtered($resource, $data['filters'] ?? []) : self::MODELS[$resource]::whereKey($data['ids']);
            $records = $query->where('is_active', ! $active)->lockForUpdate()->get();
            [$skipped, $changing] = $records->partition(fn ($record) => $active && $record instanceof Product && ! $record->price);
            if ($changing->isNotEmpty()) {
                self::MODELS[$resource]::whereKey($changing->modelKeys())->update(['is_active' => $active]);
                foreach ($changing as $record) {
                    Audit::record('catalog.'.($active ? 'activated' : 'deactivated'), $record, ['is_active' => $active, 'bulk' => true]);
                }
            }

            return [$changing->count(), $skipped->count()];
        });

        $noun = [
            'products' => ['product', 'products'], 'outlets' => ['outlet', 'outlets'], 'categories' => ['category', 'categories'],
        ][$resource][$changed === 1 ? 0 : 1];
        // Nothing could be activated: say why instead of a "0 activated" success.
        if ($changed === 0 && $skipped) {
            return back()->withErrors(['is_active' => 'No products were activated: '.$skipped.' '.($skipped === 1 ? 'has' : 'have').' no base price yet. Set the price (Edit, or import the Excel file with Harga Jual filled), then activate again.']);
        }
        $message = $changed.' '.$noun.' '.($active ? 'activated' : 'deactivated').'.';
        if ($skipped) {
            $message .= ' '.$skipped.' stayed inactive because they have no base price yet.';
        }

        return back()->with('success', $message);
    }

    /** The list query for the given filters (search by name, status, and product type). */
    private function filtered(string $resource, array $filters)
    {
        $query = self::MODELS[$resource]::query();
        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }
        if (! empty($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active');
        }
        if ($resource === 'products' && ! empty($filters['type'])) {
            $query->where('is_hamper', $filters['type'] === 'hampers');
        }

        return $query;
    }
}

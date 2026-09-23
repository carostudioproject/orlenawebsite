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

    public function index(Request $request)
    {
        $resource = $request->route('resource');
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive'], 'type' => ['nullable', 'in:regular,hampers'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = self::MODELS[$resource]::query();
        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }
        if (! empty($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active');
        }
        if ($resource === 'products') {
            $query->with('category');
            if (! empty($filters['type'])) {
                $query->where('is_hamper', $filters['type'] === 'hampers');
            }
        }
        if ($resource !== 'products') {
            $query->orderBy('position');
        }

        return Inertia::render('Admin/Catalog/Index', ['resource' => $resource, 'records' => $query->orderBy('name')->paginate(10)->withQueryString(), 'filters' => $filters]);
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
}

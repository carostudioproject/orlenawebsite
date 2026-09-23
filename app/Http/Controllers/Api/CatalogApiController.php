<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Outlet;
use App\Support\OrderCatalog;
use App\Support\PreorderDate;
use Illuminate\Http\Request;

/** Public, read-only catalog data: the same information the order form already shows. */
class CatalogApiController extends Controller
{
    public function categories()
    {
        return ['data' => Category::where('is_active', true)->orderBy('position')->orderBy('name')->get(['id', 'name', 'image'])];
    }

    public function outlets()
    {
        return ['data' => Outlet::takingPreorders()->orderBy('position')->orderBy('name')->get(['id', 'code', 'name', 'address', 'maps_url', 'image', 'is_delivery_hub'])];
    }

    /** Orderable products; prices follow `outlet_id` when given (outlet price, else base price). */
    public function products(Request $request)
    {
        $request->validate(['outlet_id' => ['nullable', 'integer', 'min:1']]);
        $outlet = $request->filled('outlet_id') ? Outlet::takingPreorders()->find($request->integer('outlet_id')) : null;
        abort_if($request->filled('outlet_id') && ! $outlet, 404, 'Outlet tidak ditemukan atau tidak menerima PO.');

        return ['data' => OrderCatalog::for($outlet)['products']];
    }

    public function schedule(PreorderDate $dates)
    {
        return ['data' => [
            'earliest_date' => $dates->earliest(), 'cutoff_time' => $dates->cutoffTime(), 'cutoff_label' => $dates->cutoffLabel(), 'timezone' => 'Asia/Makassar',
            'closed_weekdays' => $dates->closedWeekdays(), 'closed_dates' => $dates->upcomingClosedDates(),
        ]];
    }
}

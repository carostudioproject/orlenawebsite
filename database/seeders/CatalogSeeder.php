<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Outlet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        // The approved Baked Goods cards; re-running never overrides photos or order changed later.
        foreach (json_decode(file_get_contents(resource_path('content/baked-goods.json')), true, 512, JSON_THROW_ON_ERROR) as $position => $card) {
            $category = Category::firstOrCreate(['name' => $card['name']], ['image' => $card['image'], 'is_active' => true, 'show_on_website' => true]);
            if ($category->wasRecentlyCreated) {
                $category->forceFill(['position' => $position])->save();
            }
        }
        foreach (json_decode(file_get_contents(resource_path('content/outlets.json')), true, 512, JSON_THROW_ON_ERROR) as $position => $outlet) {
            // Existing outlets are operating and take PO by default; staff can switch either off per outlet.
            $record = Outlet::firstOrCreate(['code' => Str::slug($outlet['name'])], [
                'name' => $outlet['name'], 'address' => $outlet['address'], 'maps_url' => $outlet['mapsUrl'], 'image' => $outlet['image'],
                'is_active' => true, 'accepts_preorder' => true, 'show_on_website' => true,
            ]);
            // Re-running the seeder never overrides the order staff set later.
            if ($record->wasRecentlyCreated) {
                $record->forceFill(['position' => $position])->save();
            }
        }
        // Fresh installs need a delivery outlet too; the first outlet ships until staff choose another (never overrides a choice).
        if (! Outlet::where('is_delivery_hub', true)->exists()) {
            Outlet::orderBy('position')->orderBy('id')->first()?->update(['is_delivery_hub' => true]);
        }
        // Marketing categories are not SKUs. Do not invent sellable products or prices.
    }
}

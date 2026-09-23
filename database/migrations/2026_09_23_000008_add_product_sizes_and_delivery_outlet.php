<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SIZE_CATEGORIES = ['FULLSIZE BROWNIES' => 'full', 'HALFSIZE BROWNIES' => 'half'];

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Only some products come in sizes; null means the product has a single size.
            $table->string('size', 10)->nullable()->after('category_id');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('variant_snapshot', 40)->nullable()->after('category_snapshot');
        });
        Schema::table('outlets', function (Blueprint $table) {
            // Delivery orders (Gojek/Grab) are all sent from this one outlet.
            $table->boolean('is_delivery_hub')->default(false)->after('accepts_preorder');
        });

        // Size used to be a category. Fullsize/halfsize brownies become size variants under "Brownies";
        // SKUs, prices, and active flags are unchanged.
        $sized = DB::table('categories')->whereIn('name', array_keys(self::SIZE_CATEGORIES))->pluck('id', 'name');
        if ($sized->isNotEmpty()) {
            $brownies = DB::table('categories')->where('name', 'Brownies')->value('id')
                ?? DB::table('categories')->insertGetId(['name' => 'Brownies', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($sized as $name => $id) {
                DB::table('products')->where('category_id', $id)->update(['category_id' => $brownies, 'size' => self::SIZE_CATEGORIES[$name]]);
                DB::table('categories')->where('id', $id)->delete();
            }
        }

        $hub = DB::table('outlets')->where('is_active', true)->where('accepts_preorder', true)->orderBy('position')->orderBy('id')->value('id');
        if ($hub) {
            DB::table('outlets')->where('id', $hub)->update(['is_delivery_hub' => true]);
        }
    }

    public function down(): void
    {
        // The category merge is not reversed; sizes remain visible on each product until then.
        Schema::table('outlets', fn (Blueprint $table) => $table->dropColumn('is_delivery_hub'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('variant_snapshot'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('size'));
    }
};

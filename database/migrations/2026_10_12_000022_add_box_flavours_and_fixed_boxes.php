<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * products.content_type gains "flavour" (only sold inside a box, Rp 0) and "set" (a box with fixed flavours).
 * product_contents.quantity holds how many of each flavour a fixed box contains (1 for choosable contents).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_contents', function (Blueprint $table) {
            $table->unsignedSmallInteger('quantity')->default(1)->after('content_product_id');
        });
        // Rp 0, inactive products already inside a box are its flavours.
        $flavours = DB::table('product_contents as pc')->join('products as box', 'box.id', '=', 'pc.product_id')
            ->where('box.content_type', 'box')->pluck('pc.content_product_id');
        DB::table('products')->whereIn('id', $flavours)->whereNull('content_type')->where('is_active', false)
            ->where(fn ($query) => $query->whereNull('price')->orWhere('price', 0))->update(['content_type' => 'flavour']);
    }

    public function down(): void
    {
        DB::table('products')->whereIn('content_type', ['flavour', 'set'])->update(['content_type' => null, 'content_count' => null, 'content_pricing' => null]);
        Schema::table('product_contents', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('image', 500)->nullable()->after('name');
            // Active categories appear as Baked Goods cards on the homepage.
            $table->boolean('is_active')->default(true)->after('image');
            $table->integer('position')->default(0)->index()->after('is_active');
        });

        // Baked Goods cards now come from categories: keep the approved cards, photos, and order;
        // product-only categories (sizes, sauces) stay off the homepage until staff turn them on.
        DB::table('categories')->update(['is_active' => false]);
        $cards = json_decode(file_get_contents(resource_path('content/baked-goods.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($cards as $position => $card) {
            DB::table('categories')->where('name', $card['name'])->update(['image' => $card['image'], 'is_active' => true, 'position' => $position]);
        }
        DB::table('content_entries')->where('key', 'bakedGoods')->delete();
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn(['image', 'is_active', 'position']));
    }
};

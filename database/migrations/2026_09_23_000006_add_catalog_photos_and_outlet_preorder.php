<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image', 500)->nullable()->after('description');
        });
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('image', 500)->nullable()->after('maps_url');
            $table->boolean('accepts_preorder')->default(true)->after('is_active');
            $table->integer('position')->default(0)->index()->after('accepts_preorder');
        });

        // is_active used to mean "accepts PO"; it now means the outlet is operating and shown on the website.
        DB::table('outlets')->update(['accepts_preorder' => DB::raw('is_active')]);
        DB::table('outlets')->update(['is_active' => true]);

        // Outlet cards on the website now come from this table: carry over the approved photos and order.
        $cards = json_decode(file_get_contents(resource_path('content/outlets.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($cards as $position => $card) {
            DB::table('outlets')->where('code', Str::slug($card['name']))->update(['image' => $card['image'], 'position' => $position]);
        }
        DB::table('content_entries')->where('key', 'outlets')->delete();
    }

    public function down(): void
    {
        DB::table('outlets')->update(['is_active' => DB::raw('accepts_preorder')]);
        Schema::table('outlets', fn (Blueprint $table) => $table->dropColumn(['image', 'accepts_preorder', 'position']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('image'));
    }
};

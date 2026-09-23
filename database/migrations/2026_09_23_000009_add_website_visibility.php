<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['outlets', 'categories'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                // Chosen under website content; separate from whether the record is active.
                $table->boolean('show_on_website')->default(false)->after('is_active');
            });
            // Keep today's website exactly as it is: what is shown now stays shown.
            DB::table($table)->update(['show_on_website' => DB::raw('is_active')]);
        }
        // For categories "active" only meant "on the homepage"; now it means usable for products, so all return to active.
        DB::table('categories')->update(['is_active' => true]);
    }

    public function down(): void
    {
        DB::table('categories')->update(['is_active' => DB::raw('show_on_website')]);
        foreach (['outlets', 'categories'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('show_on_website'));
        }
    }
};

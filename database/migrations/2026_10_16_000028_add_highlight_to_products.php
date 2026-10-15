<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Highlighted products come first on the order form (e.g. event items), with an optional badge label and their own order.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('is_active')->index();
            $table->string('featured_label', 30)->nullable()->after('is_featured');
            $table->unsignedSmallInteger('featured_position')->default(0)->after('featured_label');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['is_featured', 'featured_label', 'featured_position']));
    }
};

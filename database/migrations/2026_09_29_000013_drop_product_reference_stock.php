<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stock stays in Erzap only; the website does not store or show stock.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['reference_stock', 'reference_stock_at']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('reference_stock')->nullable()->after('barcode');
            $table->timestamp('reference_stock_at')->nullable()->after('reference_stock');
        });
    }
};

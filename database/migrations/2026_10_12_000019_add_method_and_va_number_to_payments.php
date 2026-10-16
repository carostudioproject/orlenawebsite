<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Method the customer pays with on /bayar (qris, va_bca) and the Virtual Account number to show for VA payments.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method', 20)->nullable()->after('provider');
            $table->string('va_number', 40)->nullable()->after('qr_content');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['method', 'va_number']);
        });
    }
};

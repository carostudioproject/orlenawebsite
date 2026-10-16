<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// QRIS string from the DOKU QRIS Direct API, rendered as a QR on our own payment page (/bayar/{code}).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->text('qr_content')->nullable()->after('payment_url');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('qr_content');
        });
    }
};

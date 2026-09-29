<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Payments move from Midtrans Snap to DOKU Checkout. Old rows keep provider "midtrans".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('snap_token', 'checkout_token');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider', 20)->default('doku')->change();
            // Request-Id sent when creating the DOKU checkout; DOKU needs it to cancel an unpaid checkout.
            $table->string('provider_request_id', 128)->nullable()->after('provider_order_id');
        });
        Schema::table('payment_events', function (Blueprint $table) {
            $table->string('provider', 20)->default('doku')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->string('provider', 20)->default('midtrans')->change();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('provider_request_id');
            $table->string('provider', 20)->default('midtrans')->change();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('checkout_token', 'snap_token');
        });
    }
};

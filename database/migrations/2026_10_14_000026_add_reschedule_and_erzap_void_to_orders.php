<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rules Order Website: a paid order can be rescheduled once by staff before the cutoff (rescheduled_from/at),
 * and a sale already in Erzap that is cancelled for a refund is voided there by hand (erzap_voided_at records it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('rescheduled_from')->nullable()->after('moved_from_date');
            $table->timestamp('rescheduled_at')->nullable()->after('rescheduled_from');
            $table->timestamp('erzap_voided_at')->nullable()->after('rescheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['rescheduled_from', 'rescheduled_at', 'erzap_voided_at']));
    }
};

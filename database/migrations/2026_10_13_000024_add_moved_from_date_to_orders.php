<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The original date of an order paid after its H-1 cutoff and moved to the next open date, so staff can tell the customer.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('moved_from_date')->nullable()->after('requested_time');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('moved_from_date');
        });
    }
};

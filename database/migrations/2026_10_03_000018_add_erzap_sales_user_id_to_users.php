<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The staff member's own user ID in Erzap, so orders they confirm reach Erzap under their name.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('erzap_sales_user_id')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('erzap_sales_user_id');
        });
    }
};

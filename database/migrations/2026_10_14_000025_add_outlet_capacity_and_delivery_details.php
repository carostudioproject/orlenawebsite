<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rules Order Website (Revisi 2):
 * - outlets: daily quota (100, 30 at malls), 20 pickups per hourly slot, the first slot and closing time,
 *   and whether the outlet ships delivery (several outlets may, replacing the single delivery hub);
 * - orders: recipient WhatsApp and Google Maps link for delivery, and whether the customer asked Orlena for the nearest outlet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->unsignedInteger('daily_quota')->default(100)->after('accepts_preorder');
            $table->unsignedSmallInteger('hourly_quota')->default(20)->after('daily_quota');
            $table->time('first_slot')->default('13:00:00')->after('hourly_quota');
            $table->time('closes_at')->default('21:00:00')->after('first_slot');
            $table->boolean('delivers')->default(false)->after('closes_at');
        });
        DB::table('outlets')->update(['delivers' => DB::raw('is_delivery_hub')]);
        // The two mall outlets (Living World, Mal Bali Galeria) take 30 orders a day.
        DB::table('outlets')->where(fn ($query) => $query->whereIn('code', ['LW', 'MBG'])->orWhere('name', 'like', '%Living World%')->orWhere('name', 'like', '%Galeria%'))
            ->update(['daily_quota' => 30]);
        Schema::table('outlets', fn (Blueprint $table) => $table->dropColumn('is_delivery_hub'));

        Schema::table('orders', function (Blueprint $table) {
            $table->string('recipient_whatsapp', 20)->nullable()->after('delivery_address');
            $table->string('location_url', 500)->nullable()->after('recipient_whatsapp');
            $table->boolean('nearest_outlet')->default(false)->after('outlet_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['recipient_whatsapp', 'location_url', 'nearest_outlet']));
        Schema::table('outlets', fn (Blueprint $table) => $table->boolean('is_delivery_hub')->default(false)->after('accepts_preorder'));
        $first = DB::table('outlets')->where('delivers', true)->orderBy('position')->orderBy('id')->value('id');
        if ($first) {
            DB::table('outlets')->where('id', $first)->update(['is_delivery_hub' => true]);
        }
        Schema::table('outlets', fn (Blueprint $table) => $table->dropColumn(['daily_quota', 'hourly_quota', 'first_slot', 'closes_at', 'delivers']));
    }
};

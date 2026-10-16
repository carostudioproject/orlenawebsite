<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A product can ask the customer for a text on its cart line, e.g. the greeting on a card or the writing on a cake.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('input_label', 60)->nullable()->after('featured_position');
            $table->string('input_placeholder', 120)->nullable()->after('input_label');
            $table->boolean('input_required')->default(false)->after('input_placeholder');
            $table->unsignedSmallInteger('input_max')->default(150)->after('input_required');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('input_label_snapshot', 60)->nullable()->after('sku_snapshot');
            // One text per unit, in order (e.g. 3 cards, 3 greetings); empty texts of an optional field are left out.
            $table->json('input_texts')->nullable()->after('input_label_snapshot');
        });
        // Cards were recognised by name ("Card …", "Kartu …"); they now ask for their greeting with this option.
        $cards = DB::table('products')->get(['id', 'name'])->filter(fn ($product) => preg_match('/^(card|kartu)\b/i', trim($product->name)))->pluck('id');
        DB::table('products')->whereIn('id', $cards)->update([
            'input_label' => 'Tulisan kartu ucapan', 'input_placeholder' => 'Contoh: Selamat ulang tahun, Sinta!', 'input_max' => 300,
        ]);
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['input_label_snapshot', 'input_texts']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['input_label', 'input_placeholder', 'input_required', 'input_max']));
    }
};

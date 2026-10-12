<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hampers and boxes whose contents the customer chooses.
 * - products.content_type: null (regular), "hampers" (choose 1 item, its price is added) or "box" (choose N items, price per box).
 * - product_contents: the products allowed as contents ("Isi Dari"), kept by hand.
 * - order_items.parent_item_id: the chosen contents are lines under their hampers/box line, with their own barcode and price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('content_type', 10)->nullable()->after('hamper_contents');
            $table->unsignedSmallInteger('content_count')->nullable()->after('content_type');
            $table->string('content_pricing', 10)->nullable()->after('content_count');
        });
        Schema::create('product_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_product_id')->constrained('products')->cascadeOnDelete();
            $table->unique(['product_id', 'content_product_id']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('parent_item_id')->nullable()->after('order_id')->constrained('order_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_item_id');
        });
        Schema::dropIfExists('product_contents');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['content_type', 'content_count', 'content_pricing']);
        });
    }
};

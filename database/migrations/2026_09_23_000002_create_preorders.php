<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('whatsapp', 20)->index();
            $table->string('email', 254)->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_code', 40)->unique();
            $table->uuid('checkout_key')->unique();
            $table->char('owner_hash', 64);
            $table->char('request_hash', 64);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->string('outlet_name_snapshot', 160);
            $table->string('fulfillment_method', 20);
            $table->date('requested_date')->index();
            $table->time('requested_time')->nullable();
            $table->text('delivery_address')->nullable();
            $table->text('customer_note')->nullable();
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('delivery_fee')->nullable();
            $table->unsignedBigInteger('total');
            $table->string('order_status', 30)->default('pending_review')->index();
            $table->string('payment_status', 30)->default('not_created')->index();
            $table->timestamps();
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name_snapshot', 160);
            $table->string('category_snapshot', 120);
            $table->string('sku_snapshot', 80);
            $table->unsignedBigInteger('unit_price_snapshot');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('subtotal');
            $table->timestamps();
        });
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('customers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Items a customer added to their own order (via Order Code + WhatsApp) before payment.
        Schema::create('order_additions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // One key per add form: a double-submit or retry never adds the same items twice.
            $table->uuid('add_key')->unique();
            $table->json('items');
            $table->unsignedBigInteger('subtotal_added');
            $table->unsignedBigInteger('previous_total');
            $table->unsignedBigInteger('new_total');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_additions');
    }
};

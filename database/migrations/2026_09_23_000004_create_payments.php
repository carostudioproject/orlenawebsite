<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('provider', 20)->default('midtrans');
            $table->string('provider_order_id', 50)->unique();
            // Holds order_id only while the payment is creating/pending, so the database allows one open link per order.
            $table->unsignedBigInteger('open_order_id')->nullable()->unique();
            $table->unsignedBigInteger('amount');
            $table->string('status', 30)->index();
            $table->string('snap_token', 100)->nullable();
            $table->string('payment_url', 500)->nullable();
            $table->string('transaction_id', 80)->nullable();
            $table->string('payment_type', 40)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('reason')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['order_id', 'attempt']);
        });
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20)->default('midtrans');
            $table->char('event_key', 64)->unique();
            $table->string('source', 20);
            $table->string('provider_order_id', 50)->index();
            $table->string('transaction_status', 30);
            $table->string('fraud_status', 20)->nullable();
            $table->string('gross_amount', 30)->nullable();
            $table->string('processing_status', 30);
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('received_at')->useCurrent();
        });
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->text('note')->nullable();
        });
        Schema::table('order_reviews', function (Blueprint $table) {
            $table->string('previous_schedule', 20)->nullable();
            $table->string('schedule', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_reviews', fn (Blueprint $table) => $table->dropColumn(['previous_schedule', 'schedule']));
        Schema::table('order_status_histories', fn (Blueprint $table) => $table->dropColumn('note'));
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sizes become free-text variants (Fullsize, Halfsize, Box isi 6, Coklat, ...).
        Schema::table('products', function (Blueprint $table) {
            $table->string('variant', 40)->nullable()->after('category_id');
        });
        DB::table('products')->where('size', 'full')->update(['variant' => 'Fullsize']);
        DB::table('products')->where('size', 'half')->update(['variant' => 'Halfsize']);
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('size');
        });

        Schema::table('products', function (Blueprint $table) {
            // Hampers are products with a list of contents; any product can be limited to a sale period (e.g. an event).
            $table->boolean('is_hamper')->default(false)->index()->after('is_active');
            $table->text('hamper_contents')->nullable()->after('is_hamper');
            $table->date('sale_starts_on')->nullable()->after('hamper_contents');
            $table->date('sale_ends_on')->nullable()->after('sale_starts_on');
            // Erzap mapping and reference stock. Stock is shown to staff only and never validates or reserves a PO.
            $table->string('erzap_product_id', 80)->nullable()->index();
            $table->string('erzap_variant_id', 80)->nullable();
            $table->string('barcode', 80)->nullable()->index();
            $table->integer('reference_stock')->nullable();
            $table->timestamp('reference_stock_at')->nullable();
        });
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('erzap_outlet_id', 80)->nullable()->index();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->json('value');
            $table->timestamp('updated_at')->nullable();
        });
        Schema::create('closed_dates', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('reason', 160)->nullable();
            $table->timestamps();
        });

        Schema::create('integration_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->index();
            $table->string('type', 40);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // One sync per provider, type and subject; retries reuse the row.
            $table->string('sync_key', 191)->unique();
            $table->string('status', 20)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('external_ref', 120)->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_syncs');
        Schema::dropIfExists('closed_dates');
        Schema::dropIfExists('settings');
        Schema::table('outlets', fn (Blueprint $table) => $table->dropColumn('erzap_outlet_id'));
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_hamper', 'hamper_contents', 'sale_starts_on', 'sale_ends_on', 'erzap_product_id', 'erzap_variant_id', 'barcode', 'reference_stock', 'reference_stock_at']);
            $table->string('size', 10)->nullable()->after('category_id');
        });
        DB::table('products')->where('variant', 'Fullsize')->update(['size' => 'full']);
        DB::table('products')->where('variant', 'Halfsize')->update(['size' => 'half']);
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('variant'));
    }
};

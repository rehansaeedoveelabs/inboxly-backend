<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_abandoned_checkouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('shopify_checkout_id', 64);
            $table->string('checkout_token', 128)->nullable();
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('abandoned_checkout_url', 2048)->nullable();
            $table->decimal('total_price', 12, 2)->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'shopify_checkout_id']);
            $table->index(['store_id', 'contact_id']);
            $table->index(['store_id', 'recovered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_abandoned_checkouts');
    }
};

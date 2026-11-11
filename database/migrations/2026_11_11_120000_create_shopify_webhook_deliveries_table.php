<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // X-Shopify-Webhook-Id — unique per delivery attempt from Shopify.
            $table->string('webhook_id', 64)->unique();
            $table->string('topic', 128);
            $table->string('shop_domain');
            $table->foreignUlid('store_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['shop_domain', 'topic']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_webhook_deliveries');
    }
};

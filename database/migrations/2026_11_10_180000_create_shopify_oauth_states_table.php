<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_oauth_states', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Opaque CSRF nonce returned as Shopify `state` (unique, single-use).
            $table->string('state', 128)->unique();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->string('shop_domain');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_oauth_states');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_sync_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->json('resources');
            $table->string('status', 32);
            $table->string('customers_status', 32)->default('skipped');
            $table->string('products_status', 32)->default('skipped');
            $table->string('orders_status', 32)->default('skipped');
            $table->string('customers_cursor', 255)->nullable();
            $table->string('products_cursor', 255)->nullable();
            $table->string('orders_cursor', 255)->nullable();
            $table->unsignedInteger('customers_synced')->default(0);
            $table->unsignedInteger('products_synced')->default(0);
            $table->unsignedInteger('orders_synced')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status'], 'shopify_sync_runs_store_status_idx');
            $table->index(['store_id', 'created_at'], 'shopify_sync_runs_store_created_idx');
        });

        Schema::create('shopify_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_product_gid', 128);
            $table->string('title')->nullable();
            $table->string('handle')->nullable();
            $table->string('status', 32)->nullable();
            $table->string('product_type')->nullable();
            $table->string('vendor')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            // Non-PII only: image url, variant_count, etc.
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['store_id', 'shopify_product_gid'],
                'shopify_products_store_gid_uidx',
            );
            $table->index(['store_id', 'status'], 'shopify_products_store_status_idx');
            $table->index(['store_id', 'handle'], 'shopify_products_store_handle_idx');
        });

        Schema::create('shopify_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_order_gid', 128);
            $table->string('name')->nullable();
            $table->unsignedBigInteger('order_number')->nullable();
            $table->string('financial_status', 64)->nullable();
            $table->string('fulfillment_status', 64)->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->decimal('total_price', 14, 2)->nullable();
            // Optional Shopify customer GID only — never email/phone/address.
            $table->string('shopify_customer_gid', 128)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['store_id', 'shopify_order_gid'],
                'shopify_orders_store_gid_uidx',
            );
            $table->index(['store_id', 'processed_at'], 'shopify_orders_store_processed_idx');
            $table->index(
                ['store_id', 'shopify_customer_gid'],
                'shopify_orders_store_customer_gid_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_orders');
        Schema::dropIfExists('shopify_products');
        Schema::dropIfExists('shopify_sync_runs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_compliance_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('shop_domain');
            // customers/data_request | customers/redact | shop/redact
            $table->string('topic', 64);
            // X-Shopify-Webhook-Id — unique per Shopify delivery (replay protection).
            $table->string('webhook_id', 64)->unique();
            $table->string('shopify_customer_id', 64)->nullable();
            $table->string('shopify_data_request_id', 64)->nullable();
            // Hex SHA-256 of the raw body for integrity — not reversible to PII.
            $table->char('payload_sha256', 64)->nullable();
            $table->string('status', 32);
            $table->json('processing_checkpoint')->nullable();
            $table->json('legal_checklist')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->string('inventory_disk', 32)->nullable();
            // Private relative path — never expose raw inventory contents via mass assignment APIs.
            $table->string('inventory_path')->nullable();
            $table->json('inventory_summary')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('awaiting_policy_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index(['store_id', 'status']);
            $table->index(['shop_domain', 'topic']);
            // MySQL UNIQUE allows multiple NULLs in shopify_data_request_id, so redact/shop
            // rows without a data-request id do not collide; non-null ids are deduped.
            $table->unique(
                ['shop_domain', 'topic', 'shopify_data_request_id'],
                'shopify_compliance_data_request_uidx',
            );
        });

        Schema::create('shopify_compliance_request_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('compliance_request_id')
                ->constrained('shopify_compliance_requests')
                ->cascadeOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64);
            // Safe codes/counts only — never raw customer PII or webhook bodies.
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            // Short names: MySQL identifier limit is 64 characters.
            $table->index(
                ['compliance_request_id', 'created_at'],
                'shopify_comp_req_events_req_created_idx',
            );
            $table->index(
                ['event_type', 'created_at'],
                'shopify_comp_req_events_type_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_compliance_request_events');
        Schema::dropIfExists('shopify_compliance_requests');
    }
};

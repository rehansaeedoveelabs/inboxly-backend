<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1.1/M1.4 hardening:
 * - Prevent ordinary parent deletion from cascading away append-only audit rows.
 * - Persist workspace_id so null-store shop/redact requests remain tenant-scoped.
 * - Private encrypted correlation file path (ephemeral; not email/phone columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_compliance_request_events', function (Blueprint $table) {
            $table->dropForeign(['compliance_request_id']);
        });

        Schema::table('shopify_compliance_request_events', function (Blueprint $table) {
            $table->foreign('compliance_request_id')
                ->references('id')
                ->on('shopify_compliance_requests')
                ->restrictOnDelete();
        });

        Schema::table('shopify_compliance_requests', function (Blueprint $table) {
            $table->foreignUlid('workspace_id')
                ->nullable()
                ->after('store_id')
                ->constrained('workspaces')
                ->nullOnDelete();
            $table->string('correlation_disk', 32)->nullable()->after('inventory_path');
            $table->string('correlation_path')->nullable()->after('correlation_disk');
            $table->index(['workspace_id', 'status'], 'shopify_comp_req_workspace_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_compliance_requests', function (Blueprint $table) {
            $table->dropIndex('shopify_comp_req_workspace_status_idx');
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropColumn(['correlation_disk', 'correlation_path']);
        });

        Schema::table('shopify_compliance_request_events', function (Blueprint $table) {
            $table->dropForeign(['compliance_request_id']);
        });

        Schema::table('shopify_compliance_request_events', function (Blueprint $table) {
            $table->foreign('compliance_request_id')
                ->references('id')
                ->on('shopify_compliance_requests')
                ->cascadeOnDelete();
        });
    }
};

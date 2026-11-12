<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_compliance_requests', function (Blueprint $table) {
            // Wall-clock lease held while status=processing. Recovery may reclaim only after expiry.
            $table->timestamp('processing_lease_expires_at')->nullable()->after('processing_started_at');
            // Opaque claim token; finalize/fail only succeed when the worker still owns the lease.
            $table->string('processing_lease_token', 26)->nullable()->after('processing_lease_expires_at');

            $table->index(
                ['status', 'processing_lease_expires_at'],
                'shopify_comp_req_status_lease_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('shopify_compliance_requests', function (Blueprint $table) {
            $table->dropIndex('shopify_comp_req_status_lease_idx');
            $table->dropColumn(['processing_lease_expires_at', 'processing_lease_token']);
        });
    }
};

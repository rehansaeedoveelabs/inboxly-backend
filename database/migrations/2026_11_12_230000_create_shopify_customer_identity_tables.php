<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_customer_identities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            // Authoritative Shopify Customer GID, e.g. gid://shopify/Customer/…
            $table->string('shopify_customer_gid', 128);
            // Optional link to an Inboxly contact; hard-delete of contact nulls this FK.
            $table->foreignUlid('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32);
            $table->string('link_source', 32)->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('unlinked_at')->nullable();
            $table->timestamp('shopify_deleted_at')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('sync_error_code', 64)->nullable();
            // Non-PII flags/counts only — never email/phone/name snapshots.
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['store_id', 'shopify_customer_gid'],
                'shopify_cust_identities_store_gid_uidx',
            );
            // MySQL UNIQUE allows multiple NULLs, so unlinked identities do not collide.
            $table->unique(
                ['store_id', 'contact_id'],
                'shopify_cust_identities_store_contact_uidx',
            );
            $table->index(
                ['store_id', 'status'],
                'shopify_cust_identities_store_status_idx',
            );
        });

        Schema::create('shopify_identity_conflicts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shopify_customer_identity_id')
                ->constrained('shopify_customer_identities')
                ->cascadeOnDelete();
            $table->foreignUlid('candidate_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('conflict_type', 64);
            $table->string('status', 32);
            // Booleans/counts only — never raw email/phone.
            $table->json('match_signals')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUlid('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_code', 64)->nullable();
            $table->json('resolution_meta')->nullable();
            $table->timestamps();

            $table->index(
                ['shopify_customer_identity_id', 'status'],
                'shopify_id_conflicts_identity_status_idx',
            );
            $table->index(
                ['status', 'created_at'],
                'shopify_id_conflicts_status_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_identity_conflicts');
        Schema::dropIfExists('shopify_customer_identities');
    }
};

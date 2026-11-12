<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_pages', function (Blueprint $table) {
            $table->timestamp('page_token_refreshed_at')->nullable()->after('selected_at');
            $table->timestamp('page_token_expires_at')->nullable()->after('page_token_refreshed_at');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->text('meta_message')->nullable()->after('send_error');
            $table->string('meta_link', 2048)->nullable()->after('meta_message');
            $table->foreignUlid('meta_asset_id')->nullable()->after('meta_link')
                ->constrained('assets')->nullOnDelete();
        });

        Schema::create('campaign_meta_publications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('meta_page_id')->constrained('meta_pages')->cascadeOnDelete();
            $table->string('status', 32);
            $table->boolean('simulated')->default(false);
            $table->string('idempotency_key', 128);
            $table->string('content_hash', 64);
            $table->text('message_snapshot');
            $table->string('link_snapshot', 2048)->nullable();
            $table->foreignUlid('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('provider_post_id')->nullable();
            $table->string('provider', 32)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code')->nullable();
            $table->string('error_message', 900)->nullable();
            $table->boolean('error_retryable')->default(false);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('publishing_started_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['idempotency_key']);
            $table->index(['campaign_id', 'status']);
            $table->index(['store_id', 'status']);
            $table->index(['meta_page_id', 'status']);
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_meta_publications');

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meta_asset_id');
            $table->dropColumn(['meta_message', 'meta_link']);
        });

        Schema::table('meta_pages', function (Blueprint $table) {
            $table->dropColumn(['page_token_refreshed_at', 'page_token_expires_at']);
        });
    }
};

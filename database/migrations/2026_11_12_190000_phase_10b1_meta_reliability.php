<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_meta_publications', function (Blueprint $table) {
            $table->string('lease_token', 32)->nullable()->after('lease_expires_at');
            $table->string('publish_intent', 26)->nullable()->after('content_hash');

            $table->index(['id', 'lease_token']);
        });

        // Base key is ~118 chars; intentional republish appends :{ulid} (~145).
        // Use raw ALTER so we do not require doctrine/dbal for ->change().
        DB::statement('ALTER TABLE campaign_meta_publications MODIFY idempotency_key VARCHAR(191) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE campaign_meta_publications MODIFY idempotency_key VARCHAR(128) NOT NULL');

        Schema::table('campaign_meta_publications', function (Blueprint $table) {
            $table->dropIndex(['id', 'lease_token']);
            $table->dropColumn(['lease_token', 'publish_intent']);
        });
    }
};

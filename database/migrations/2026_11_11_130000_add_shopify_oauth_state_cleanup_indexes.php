<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_oauth_states', function (Blueprint $table) {
            // Supports scheduled prune of expired rows without scanning by store.
            $table->index('expires_at');
            // Supports prune of sufficiently old consumed rows.
            $table->index('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_oauth_states', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropIndex(['consumed_at']);
        });
    }
};

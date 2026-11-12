<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_connections', function (Blueprint $table) {
            // From Meta debug_token.data_access_expires_at when present and > 0.
            $table->timestamp('data_access_expires_at')->nullable()->after('user_token_expires_at');
            $table->index('data_access_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('meta_connections', function (Blueprint $table) {
            $table->dropIndex(['data_access_expires_at']);
            $table->dropColumn('data_access_expires_at');
        });
    }
};

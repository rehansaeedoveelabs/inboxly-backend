<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // MVP: one Meta user connection per store.
            $table->foreignUlid('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('meta_user_id');
            $table->text('user_access_token'); // encrypted via Eloquent cast
            $table->timestamp('user_token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('status', 32); // connected | needs_reauth
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('reauth_required_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'user_token_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_connections');
    }
};

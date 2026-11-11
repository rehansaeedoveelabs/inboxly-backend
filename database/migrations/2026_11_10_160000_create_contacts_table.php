<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            // Empty values are stored as NULL: MySQL allows many NULLs in a unique index.
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('source', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['store_id', 'email']);
            $table->unique(['store_id', 'phone']);
            // Keyset pagination: ORDER BY created_at, id within a store.
            $table->index(['store_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('original_filename');
            $table->string('mime_type', 128);
            $table->string('extension', 16);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('disk', 32)->default('local');
            // Private relative path — never accept from clients or expose via API.
            $table->string('path');
            $table->string('alt')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'created_at']);
            $table->index(['store_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};

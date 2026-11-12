<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('category', 64)->default('general');
            $table->string('status', 16)->default('draft');
            // Block-document JSON is the source of truth.
            $table->json('document');
            // Sanitized HTML derived from document (never trust client HTML as source).
            $table->mediumText('html')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'category']);
            $table->index(['store_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('meta_connection_id')->constrained('meta_connections')->cascadeOnDelete();
            $table->string('meta_page_id');
            $table->string('name');
            $table->text('page_access_token'); // encrypted via Eloquent cast; only for selected Pages
            $table->json('tasks')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'meta_page_id']);
            $table->index(['store_id', 'is_default']);
            $table->index('meta_connection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_pages');
    }
};

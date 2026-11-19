<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('type', 32); // popup | embedded | landing_page | flyout | fullscreen
            $table->string('status', 32)->default('draft'); // draft | enabled
            $table->json('definition');
            $table->unsignedInteger('submissions_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'type']);
            $table->index(['store_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};

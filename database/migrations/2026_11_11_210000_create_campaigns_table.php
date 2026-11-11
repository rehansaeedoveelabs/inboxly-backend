<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('channel', 16);
            $table->string('status', 16)->default('draft');
            $table->string('subject')->nullable();
            $table->string('preview_text')->nullable();
            $table->text('body')->nullable();
            $table->string('audience_type', 32);
            $table->foreignUlid('segment_id')->nullable()->constrained('segments')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedInteger('audience_count')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};

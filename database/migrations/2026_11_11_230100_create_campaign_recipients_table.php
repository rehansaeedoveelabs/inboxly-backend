<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('address');
            $table->string('status', 32)->default('pending');
            $table->string('provider', 32)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['campaign_id', 'status']);
            $table->index(['provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brevo_webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Deterministic fingerprint for idempotent processing (no HMAC body signature from Brevo).
            $table->string('dedupe_key', 128)->unique();
            $table->string('event', 64);
            $table->string('email')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->foreignUlid('campaign_recipient_id')->nullable()
                ->constrained('campaign_recipients')->nullOnDelete();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['provider_message_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brevo_webhook_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_consents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('contact_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 32);
            $table->string('source', 64)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamps();

            $table->unique(['contact_id', 'channel']);
            // Segment / list filters: "contacts whose email consent is subscribed".
            $table->index(['channel', 'status', 'contact_id']);
        });

        // Append-only audit trail of every consent change.
        Schema::create('consent_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('contact_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 32);
            $table->string('source', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['contact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_events');
        Schema::dropIfExists('contact_consents');
    }
};

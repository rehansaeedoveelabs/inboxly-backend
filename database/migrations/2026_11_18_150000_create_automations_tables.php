<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 16)->default('draft');
            $table->string('trigger_type', 64);
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['store_id', 'trigger_type', 'status']);
        });

        Schema::create('automation_steps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('type', 32);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'position']);
        });

        Schema::create('automation_enrollments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('automation_id')->constrained('automations')->cascadeOnDelete();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('current_step_position')->default(0);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 64)->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'contact_id']);
            $table->index(['status', 'next_run_at']);
            $table->index(['store_id', 'status']);
            $table->index(['automation_id', 'status']);
        });

        Schema::create('automation_step_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('enrollment_id')->constrained('automation_enrollments')->cascadeOnDelete();
            $table->foreignUlid('automation_step_id')->constrained('automation_steps')->cascadeOnDelete();
            $table->unsignedInteger('attempt')->default(0);
            $table->string('status', 16)->default('pending');
            $table->string('idempotency_key', 191);
            $table->string('provider_message_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'automation_step_id']);
            $table->unique('idempotency_key');
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_step_runs');
        Schema::dropIfExists('automation_enrollments');
        Schema::dropIfExists('automation_steps');
        Schema::dropIfExists('automations');
    }
};

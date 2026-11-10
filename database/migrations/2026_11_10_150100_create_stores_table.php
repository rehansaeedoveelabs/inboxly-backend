<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('platform', 32)->default('shopify');
            $table->string('connection_status', 32)->default('pending');
            $table->string('timezone', 64)->default('UTC');
            $table->string('currency', 3)->default('USD');
            $table->json('metadata')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};

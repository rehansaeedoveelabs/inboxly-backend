<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('store_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('address');
            $table->string('reason', 32);
            $table->string('source', 64)->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'channel', 'address']);
            $table->index(['store_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};

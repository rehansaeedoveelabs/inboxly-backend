<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('sending_started_at')->nullable()->after('audience_count');
            $table->timestamp('sent_at')->nullable()->after('sending_started_at');
            $table->unsignedInteger('sent_count')->default(0)->after('sent_at');
            $table->unsignedInteger('failed_count')->default(0)->after('sent_count');
            $table->unsignedInteger('skipped_count')->default(0)->after('failed_count');
            $table->string('send_error', 2000)->nullable()->after('skipped_count');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'sending_started_at',
                'sent_at',
                'sent_count',
                'failed_count',
                'skipped_count',
                'send_error',
            ]);
        });
    }
};

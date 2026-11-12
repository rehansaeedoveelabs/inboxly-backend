<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('content_format', 16)->default('plain')->after('body');
            $table->mediumText('html_body')->nullable()->after('content_format');
            $table->json('content_document')->nullable()->after('html_body');
            $table->foreignUlid('email_template_id')->nullable()->after('content_document')
                ->constrained('email_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_template_id');
            $table->dropColumn(['content_format', 'html_body', 'content_document']);
        });
    }
};

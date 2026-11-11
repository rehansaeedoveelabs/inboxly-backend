<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Set on restore; null means marketing eligibility is not blocked by review.
            $table->timestamp('consent_review_required_at')->nullable()->after('metadata');
        });

        Schema::table('contact_imports', function (Blueprint $table) {
            $table->unsignedInteger('restored_count')->default(0)->after('updated_count');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('consent_review_required_at');
        });

        Schema::table('contact_imports', function (Blueprint $table) {
            $table->dropColumn('restored_count');
        });
    }
};

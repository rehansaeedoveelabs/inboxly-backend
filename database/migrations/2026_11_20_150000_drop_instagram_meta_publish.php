<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_meta_publications', function (Blueprint $table) {
            $table->dropIndex('cmp_campaign_channel_status_idx');
            $table->dropColumn('channel');
        });

        Schema::table('meta_pages', function (Blueprint $table) {
            $table->dropColumn([
                'instagram_business_account_id',
                'instagram_username',
                'instagram_linked_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('meta_pages', function (Blueprint $table) {
            $table->string('instagram_business_account_id')->nullable()->after('name');
            $table->string('instagram_username')->nullable()->after('instagram_business_account_id');
            $table->timestamp('instagram_linked_at')->nullable()->after('instagram_username');
        });

        Schema::table('campaign_meta_publications', function (Blueprint $table) {
            $table->string('channel', 16)->default('facebook')->after('meta_page_id');
            $table->index(['campaign_id', 'channel', 'status'], 'cmp_campaign_channel_status_idx');
        });
    }
};

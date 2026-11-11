<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Shopify identity — globally unique when set (pending/demo rows stay null).
            $table->string('shopify_shop_id', 64)->nullable()->after('is_demo');
            $table->string('shopify_shop_domain')->nullable()->after('shopify_shop_id');

            // Expiring offline tokens (encrypted at the model layer). Text holds ciphertext.
            $table->text('shopify_access_token')->nullable()->after('shopify_shop_domain');
            $table->text('shopify_refresh_token')->nullable()->after('shopify_access_token');
            $table->timestamp('shopify_access_token_expires_at')->nullable()->after('shopify_refresh_token');
            $table->timestamp('shopify_refresh_token_expires_at')->nullable()->after('shopify_access_token_expires_at');

            // Granted scopes from the token exchange response (may be empty for connect-only).
            $table->json('shopify_scopes')->nullable()->after('shopify_refresh_token_expires_at');

            $table->unique('shopify_shop_id');
            $table->unique('shopify_shop_domain');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique(['shopify_shop_id']);
            $table->dropUnique(['shopify_shop_domain']);
            $table->dropColumn([
                'shopify_shop_id',
                'shopify_shop_domain',
                'shopify_access_token',
                'shopify_refresh_token',
                'shopify_access_token_expires_at',
                'shopify_refresh_token_expires_at',
                'shopify_scopes',
            ]);
        });
    }
};

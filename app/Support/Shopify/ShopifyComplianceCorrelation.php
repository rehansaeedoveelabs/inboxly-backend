<?php

namespace App\Support\Shopify;

use App\Models\ShopifyComplianceRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ShopifyComplianceCorrelation
{
    /**
     * @return array{shopify_customer_id: ?string, email: ?string, phone: ?string}|null
     */
    public static function read(ShopifyComplianceRequest $request): ?array
    {
        if (! filled($request->correlation_path) || ! filled($request->correlation_disk)) {
            return null;
        }

        try {
            $encrypted = Storage::disk($request->correlation_disk)->get($request->correlation_path);
            if (! is_string($encrypted) || $encrypted === '') {
                return null;
            }
            $decoded = json_decode(Crypt::decryptString($encrypted), true);
            if (! is_array($decoded)) {
                return null;
            }

            return [
                'shopify_customer_id' => isset($decoded['shopify_customer_id']) && is_string($decoded['shopify_customer_id'])
                    ? $decoded['shopify_customer_id']
                    : null,
                'email' => isset($decoded['email']) && is_string($decoded['email']) ? $decoded['email'] : null,
                'phone' => isset($decoded['phone']) && is_string($decoded['phone']) ? $decoded['phone'] : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Best-effort delete of a correlation file after DB pointers were cleared under lease ownership.
     * Do not call this to "take ownership" — stale workers must not clear another attempt's file
     * via model forget; only the owning finalize path should clear DB pointers first.
     */
    public static function deleteFile(?string $disk, ?string $path): void
    {
        if (! filled($disk) || ! filled($path)) {
            return;
        }

        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    }

    /**
     * @deprecated Prefer owned finalize clearing pointers + deleteFile. Kept for rare ops use.
     */
    public static function forget(ShopifyComplianceRequest $request): void
    {
        $disk = $request->correlation_disk;
        $path = $request->correlation_path;

        $request->forceFill([
            'correlation_disk' => null,
            'correlation_path' => null,
        ])->save();

        self::deleteFile(
            is_string($disk) ? $disk : null,
            is_string($path) ? $path : null,
        );
    }
}

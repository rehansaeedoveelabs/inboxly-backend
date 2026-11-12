<?php

namespace App\Actions\Meta;

use App\Models\Asset;
use App\Models\Campaign;
use Illuminate\Validation\ValidationException;

class UpdateCampaignSocialContent
{
    /**
     * @param  array{message?: string|null, link?: string|null, asset_id?: string|null}  $input
     */
    public function handle(Campaign $campaign, array $input): Campaign
    {
        $message = isset($input['message']) ? trim((string) $input['message']) : ($campaign->meta_message ?? '');
        $link = array_key_exists('link', $input)
            ? (isset($input['link']) ? trim((string) $input['link']) : null)
            : $campaign->meta_link;
        $assetId = array_key_exists('asset_id', $input)
            ? $input['asset_id']
            : $campaign->meta_asset_id;

        if ($message === '') {
            throw ValidationException::withMessages([
                'message' => ['A Facebook message is required.'],
            ]);
        }

        if (mb_strlen($message) > 5000) {
            throw ValidationException::withMessages([
                'message' => ['Facebook message must be 5000 characters or fewer.'],
            ]);
        }

        if ($link !== null && $link !== '') {
            if (filter_var($link, FILTER_VALIDATE_URL) === false) {
                throw ValidationException::withMessages([
                    'link' => ['Enter a valid http(s) destination URL.'],
                ]);
            }
            $scheme = parse_url($link, PHP_URL_SCHEME);
            if (! in_array($scheme, ['http', 'https'], true)) {
                throw ValidationException::withMessages([
                    'link' => ['Destination URL must use http or https.'],
                ]);
            }
        } else {
            $link = null;
        }

        if ($assetId !== null && $assetId !== '') {
            $asset = Asset::query()
                ->where('store_id', $campaign->store_id)
                ->whereKey($assetId)
                ->first();
            if ($asset === null) {
                throw ValidationException::withMessages([
                    'asset_id' => ['Image asset not found in this store.'],
                ]);
            }
            if (! str_starts_with((string) $asset->mime_type, 'image/')) {
                throw ValidationException::withMessages([
                    'asset_id' => ['Only image assets can be attached to Facebook posts.'],
                ]);
            }
            if (! $asset->fileExists()) {
                throw ValidationException::withMessages([
                    'asset_id' => ['The selected image file is missing from storage.'],
                ]);
            }
        } else {
            $assetId = null;
        }

        $campaign->forceFill([
            'meta_message' => $message,
            'meta_link' => $link,
            'meta_asset_id' => $assetId,
        ])->save();

        return $campaign->fresh();
    }
}

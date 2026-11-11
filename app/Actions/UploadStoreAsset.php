<?php

namespace App\Actions;

use App\Models\Asset;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadStoreAsset
{
    public const MAX_BYTES = 5_242_880; // 5 MB

    /** @var list<string> */
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function handle(Store $store, User $user, UploadedFile $file, ?string $alt = null): Asset
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $filename = (string) Str::ulid().'.'.$extension;
        $path = 'assets/'.$store->getKey().'/'.$filename;

        Storage::disk('local')->putFileAs('assets/'.$store->getKey(), $file, $filename);

        $width = null;
        $height = null;
        $imageSize = @getimagesize($file->getRealPath() ?: '');
        if (is_array($imageSize)) {
            $width = $imageSize[0] ?? null;
            $height = $imageSize[1] ?? null;
        }

        $asset = new Asset([
            'store_id' => $store->getKey(),
            'uploaded_by' => $user->getKey(),
            'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: $filename,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'extension' => $extension,
            'byte_size' => $file->getSize() ?: 0,
            'width' => $width,
            'height' => $height,
            'disk' => 'local',
            'alt' => $alt,
        ]);
        $asset->path = $path;
        $asset->save();

        return $asset;
    }
}

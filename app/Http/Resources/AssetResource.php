<?php

namespace App\Http\Resources;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Asset */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'uploaded_by' => $this->uploaded_by,
            'name' => $this->name,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'byte_size' => $this->byte_size,
            'width' => $this->width,
            'height' => $this->height,
            'alt' => $this->alt,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

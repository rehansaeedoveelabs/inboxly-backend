<?php

namespace App\Http\Resources;

use App\Models\ContactExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContactExport */
class ContactExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'user_id' => $this->user_id,
            'status' => $this->status->value,
            'filters' => $this->filters ?? [],
            'original_filename' => $this->original_filename,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'byte_size' => $this->byte_size,
            'error_summary' => $this->error_summary,
            'expires_at' => $this->expires_at,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'download_available' => $this->isDownloadable(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

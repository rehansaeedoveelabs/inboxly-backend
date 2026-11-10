<?php

namespace App\Http\Resources;

use App\Models\ContactImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContactImport */
class ContactImportResource extends JsonResource
{
    /** options.rows (raw PII) is never exposed. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'user_id' => $this->user_id,
            'status' => $this->status->value,
            'original_filename' => $this->original_filename,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'created_count' => $this->created_count,
            'updated_count' => $this->updated_count,
            'failed_rows' => $this->failed_rows,
            'error_summary' => $this->error_summary,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Form;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Form */
class FormResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'created_by' => $this->created_by,
            'public_token' => $this->public_token,
            'embed_url' => $this->public_token
                ? url('/api/v1/public/forms/'.$this->public_token.'/embed')
                : null,
            'name' => $this->name,
            'type' => $this->type?->value,
            'status' => $this->status?->value,
            'definition' => $this->definition,
            'submissions_count' => $this->submissions_count,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

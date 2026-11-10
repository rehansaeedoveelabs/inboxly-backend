<?php

namespace App\Models;

use Database\Factories\SegmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Segment extends Model
{
    /** @use HasFactory<SegmentFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = ['store_id', 'name', 'description', 'rules'];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}

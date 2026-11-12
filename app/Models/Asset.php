<?php

namespace App\Models;

use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'store_id', 'uploaded_by', 'name', 'original_filename', 'mime_type',
        'extension', 'byte_size', 'width', 'height', 'disk', 'alt',
    ];

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function fileExists(): bool
    {
        return is_string($this->path)
            && $this->path !== ''
            && Storage::disk($this->disk ?: 'local')->exists($this->path);
    }
}

<?php

namespace App\Models;

use App\Enums\ContactExportStatus;
use Database\Factories\ContactExportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ContactExport extends Model
{
    /** @use HasFactory<ContactExportFactory> */
    use HasFactory, HasUlids;

    public const EXPIRY_DAYS = 7;

    /**
     * Processing exports older than this are treated as crashed.
     * Must exceed ProcessContactExportJob timeout (300s) with headroom.
     */
    public const STALE_PROCESSING_SECONDS = 900;

    protected $fillable = [
        'store_id', 'user_id', 'status', 'filters', 'original_filename',
        'total_rows', 'processed_rows', 'byte_size', 'error_summary',
        'expires_at', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContactExportStatus::class,
            'filters' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'byte_size' => 'integer',
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function fileExists(): bool
    {
        return is_string($this->path)
            && $this->path !== ''
            && Storage::disk($this->disk ?: 'local')->exists($this->path);
    }

    /** Completed, unexpired, and file present — safe to stream a download. */
    public function isDownloadable(): bool
    {
        return $this->status === ContactExportStatus::Completed
            && ! $this->isExpired()
            && $this->fileExists();
    }
}

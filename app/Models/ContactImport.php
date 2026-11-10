<?php

namespace App\Models;

use App\Enums\ContactImportStatus;
use Database\Factories\ContactImportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactImport extends Model
{
    /** @use HasFactory<ContactImportFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'store_id', 'user_id', 'status', 'original_filename', 'total_rows', 'processed_rows',
        'created_count', 'updated_count', 'failed_rows', 'error_summary', 'options',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContactImportStatus::class,
            'options' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'failed_rows' => 'integer',
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
}

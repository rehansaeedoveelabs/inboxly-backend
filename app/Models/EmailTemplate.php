<?php

namespace App\Models;

use App\Enums\EmailTemplateStatus;
use Database\Factories\EmailTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailTemplate extends Model
{
    /** @use HasFactory<EmailTemplateFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'store_id', 'created_by', 'name', 'category', 'status', 'document', 'html',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmailTemplateStatus::class,
            'document' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

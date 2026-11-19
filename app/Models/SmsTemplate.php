<?php

namespace App\Models;

use App\Enums\SmsTemplateStatus;
use Database\Factories\SmsTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SmsTemplate extends Model
{
    /** @use HasFactory<SmsTemplateFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'store_id', 'created_by', 'name', 'category', 'status', 'body',
    ];

    protected function casts(): array
    {
        return [
            'status' => SmsTemplateStatus::class,
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

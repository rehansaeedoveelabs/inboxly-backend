<?php

namespace App\Models;

use App\Enums\FormStatus;
use App\Enums\FormType;
use Database\Factories\FormFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Form extends Model
{
    /** @use HasFactory<FormFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'store_id', 'created_by', 'name', 'type', 'status', 'definition', 'submissions_count',
        'public_token',
    ];

    protected function casts(): array
    {
        return [
            'type' => FormType::class,
            'status' => FormStatus::class,
            'definition' => 'array',
            'submissions_count' => 'integer',
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

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }
}

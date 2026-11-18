<?php

namespace App\Models;

use App\Enums\AutomationStatus;
use App\Enums\AutomationTriggerType;
use Database\Factories\AutomationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Automation extends Model
{
    /** @use HasFactory<AutomationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'store_id',
        'created_by',
        'name',
        'status',
        'trigger_type',
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationStatus::class,
            'trigger_type' => AutomationTriggerType::class,
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

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStep::class)->orderBy('position');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(AutomationEnrollment::class);
    }
}

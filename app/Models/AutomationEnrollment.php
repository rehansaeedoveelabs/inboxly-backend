<?php

namespace App\Models;

use App\Enums\AutomationEnrollmentStatus;
use Database\Factories\AutomationEnrollmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationEnrollment extends Model
{
    /** @use HasFactory<AutomationEnrollmentFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'automation_id',
        'store_id',
        'contact_id',
        'status',
        'current_step_position',
        'next_run_at',
        'lease_expires_at',
        'enrolled_at',
        'completed_at',
        'cancelled_at',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationEnrollmentStatus::class,
            'current_step_position' => 'integer',
            'next_run_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function stepRuns(): HasMany
    {
        return $this->hasMany(AutomationStepRun::class, 'enrollment_id');
    }
}

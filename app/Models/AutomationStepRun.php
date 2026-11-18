<?php

namespace App\Models;

use App\Enums\AutomationStepRunStatus;
use Database\Factories\AutomationStepRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationStepRun extends Model
{
    /** @use HasFactory<AutomationStepRunFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'enrollment_id',
        'automation_step_id',
        'attempt',
        'status',
        'idempotency_key',
        'provider_message_id',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
        'lease_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationStepRunStatus::class,
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'lease_expires_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(AutomationEnrollment::class, 'enrollment_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(AutomationStep::class, 'automation_step_id');
    }

    public static function idempotencyKeyFor(string $enrollmentId, string $stepId): string
    {
        return 'automation:'.$enrollmentId.':'.$stepId;
    }
}

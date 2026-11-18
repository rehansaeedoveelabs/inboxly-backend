<?php

namespace App\Models;

use App\Enums\AutomationStepType;
use Database\Factories\AutomationStepFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationStep extends Model
{
    /** @use HasFactory<AutomationStepFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'automation_id',
        'position',
        'type',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'type' => AutomationStepType::class,
            'config' => 'array',
            'position' => 'integer',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationStepRun::class);
    }

    public function delayMinutes(): int
    {
        return max(0, (int) ($this->config['delay_minutes'] ?? 0));
    }

    public function emailSubject(): string
    {
        return trim((string) ($this->config['subject'] ?? ''));
    }

    public function emailBody(): string
    {
        return (string) ($this->config['body'] ?? '');
    }

    public function emailHtmlBody(): string
    {
        $html = $this->config['html_body'] ?? null;

        return is_string($html) ? $html : '';
    }

    public function conditionPredicate(): string
    {
        return (string) ($this->config['predicate'] ?? '');
    }

    public function conditionTagId(): ?string
    {
        $tagId = $this->config['tag_id'] ?? null;

        return is_string($tagId) && $tagId !== '' ? $tagId : null;
    }

    /**
     * @return string|int  'next' | 'end' | absolute position
     */
    public function conditionGoto(bool $passed): string|int
    {
        $key = $passed ? 'on_true_goto' : 'on_false_goto';
        $target = $this->config[$key] ?? ($passed ? 'next' : 'end');

        if ($target === 'next' || $target === 'end') {
            return $target;
        }

        return (int) $target;
    }
}

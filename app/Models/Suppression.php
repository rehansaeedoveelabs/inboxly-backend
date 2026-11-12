<?php

namespace App\Models;

use App\Enums\CampaignChannel;
use App\Enums\SuppressionReason;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Suppression extends Model
{
    use HasUlids;

    protected $fillable = [
        'store_id', 'channel', 'address', 'reason', 'source',
    ];

    protected function casts(): array
    {
        return [
            'channel' => CampaignChannel::class,
            'reason' => SuppressionReason::class,
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}

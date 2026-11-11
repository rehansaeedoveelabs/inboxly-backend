<?php

namespace App\Models;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use Database\Factories\ContactConsentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactConsent extends Model
{
    /** @use HasFactory<ContactConsentFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['contact_id', 'channel', 'status', 'source', 'consented_at'];

    protected function casts(): array
    {
        return [
            'channel' => ConsentChannel::class,
            'status' => ConsentStatus::class,
            'consented_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}

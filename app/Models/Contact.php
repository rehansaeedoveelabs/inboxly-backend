<?php

namespace App\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'store_id', 'email', 'phone', 'first_name', 'last_name',
        'locale', 'timezone', 'source', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** Lower-cased, trimmed; empty becomes null so MySQL unique indexes allow many "no email" rows. */
    public static function normalizeEmail(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    /** Keeps a leading "+" and digits only; empty becomes null. */
    public static function normalizePhone(mixed $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $phone = trim($phone);
        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return null;
        }

        return str_starts_with($phone, '+') ? '+'.$digits : $digits;
    }

    protected function email(): Attribute
    {
        return Attribute::set(fn ($value) => self::normalizeEmail($value));
    }

    protected function phone(): Attribute
    {
        return Attribute::set(fn ($value) => self::normalizePhone($value));
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(ContactConsent::class);
    }

    public function consentEvents(): HasMany
    {
        return $this->hasMany(ConsentEvent::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tag')->withPivot('created_at');
    }
}

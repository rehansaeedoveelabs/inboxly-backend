<?php

namespace Database\Factories;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\Contact;
use App\Models\ContactConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactConsent>
 */
class ContactConsentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Nonsubscribed,
            'source' => null,
            'consented_at' => null,
        ];
    }

    public function subscribed(): static
    {
        return $this->state(fn () => ['status' => ConsentStatus::Subscribed, 'consented_at' => now()]);
    }
}

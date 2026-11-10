<?php

namespace Database\Factories;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\ConsentEvent;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentEvent>
 */
class ConsentEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'channel' => ConsentChannel::Email,
            'status' => ConsentStatus::Subscribed,
            'source' => 'factory',
            'meta' => null,
        ];
    }
}

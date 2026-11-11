<?php

namespace Database\Factories;

use App\Enums\CampaignAudienceType;
use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'created_by' => User::factory(),
            'name' => fake()->sentence(3),
            'channel' => CampaignChannel::Email,
            'status' => CampaignStatus::Draft,
            'subject' => fake()->sentence(4),
            'preview_text' => fake()->sentence(6),
            'body' => fake()->paragraph(),
            'audience_type' => CampaignAudienceType::AllEligible,
            'segment_id' => null,
            'scheduled_at' => null,
            'audience_count' => null,
        ];
    }

    public function sms(): static
    {
        return $this->state(fn () => [
            'channel' => CampaignChannel::Sms,
            'subject' => null,
            'preview_text' => null,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Scheduled,
            'scheduled_at' => now('UTC')->addDay(),
            'audience_count' => 0,
        ]);
    }
}

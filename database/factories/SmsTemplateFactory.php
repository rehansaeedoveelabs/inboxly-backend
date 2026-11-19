<?php

namespace Database\Factories;

use App\Enums\SmsTemplateStatus;
use App\Models\SmsTemplate;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsTemplate>
 */
class SmsTemplateFactory extends Factory
{
    protected $model = SmsTemplate::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'created_by' => User::factory(),
            'name' => fake()->words(3, true),
            'category' => 'promo',
            'status' => SmsTemplateStatus::Draft,
            'body' => 'Hi {{first_name}}, enjoy 15% off at {{shop_name}}: {{sale_url}}',
        ];
    }
}

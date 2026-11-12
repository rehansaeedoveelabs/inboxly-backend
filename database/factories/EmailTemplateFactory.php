<?php

namespace Database\Factories;

use App\Enums\EmailTemplateStatus;
use App\Models\EmailTemplate;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    public function definition(): array
    {
        $document = [
            'version' => 1,
            'settings' => ['width' => 600, 'background' => '#ffffff'],
            'blocks' => [
                [
                    'id' => 'blk_header',
                    'type' => 'header',
                    'props' => ['html' => '<p><strong>Hello</strong></p>', 'align' => 'left'],
                ],
                [
                    'id' => 'blk_text',
                    'type' => 'text',
                    'props' => ['html' => '<p>Welcome to our store.</p>', 'align' => 'left'],
                ],
            ],
        ];

        return [
            'store_id' => Store::factory(),
            'created_by' => User::factory(),
            'name' => fake()->sentence(3),
            'category' => 'general',
            'status' => EmailTemplateStatus::Draft,
            'document' => $document,
            'html' => '<p>Welcome to our store.</p>',
        ];
    }
}

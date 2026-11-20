<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\Store;
use App\Support\Messaging\MergeTagPersonalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MergeTagPersonalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_replaces_known_tags_and_leaves_unknown(): void
    {
        $personalizer = new MergeTagPersonalizer;
        $out = $personalizer->replace(
            'Hi {{ first_name }}, welcome to {{shop_name}} — {{unknown_tag}}',
            [
                'first_name' => 'Sam',
                'shop_name' => 'Honey Shop',
            ],
        );

        $this->assertSame('Hi Sam, welcome to Honey Shop — {{unknown_tag}}', $out);
    }

    public function test_vars_for_store_and_contact(): void
    {
        $store = Store::factory()->create([
            'name' => 'Acme Goods',
            'metadata' => [
                'brand' => [
                    'shop_name' => 'Acme',
                    'address' => '1 Main',
                    'city' => 'Austin',
                    'country' => 'USA',
                ],
            ],
        ]);
        $contact = new Contact(['first_name' => 'Riley']);

        $vars = (new MergeTagPersonalizer)->varsFor($store, $contact);

        $this->assertSame('Acme', $vars['shop_name']);
        $this->assertSame('Riley', $vars['first_name']);
        $this->assertSame('1 Main', $vars['address']);
        $this->assertSame('Austin', $vars['city']);
        $this->assertSame('USA', $vars['country']);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class StoreBrandTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_brand_get_and_update_persists_in_metadata(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/brand")
            ->assertOk()
            ->assertJsonPath('data.id', $store->id);

        $this->spa()->actingAs($admin)
            ->putJson("/api/v1/stores/{$store->id}/brand", [
                'colors' => [
                    'accent' => '#112233',
                    'dark' => '#000000',
                    'light' => '#FFFFFF',
                ],
                'fonts' => [
                    'headings' => 'Georgia',
                    'body' => 'Inter',
                ],
                'social_links' => [
                    ['network' => 'Instagram', 'url' => 'https://instagram.com/acme'],
                ],
                'logo_name' => 'logo.png',
            ])
            ->assertOk()
            ->assertJsonPath('data.metadata.brand.colors.accent', '#112233')
            ->assertJsonPath('data.metadata.brand.fonts.headings', 'Georgia')
            ->assertJsonPath('data.metadata.brand.social_links.0.network', 'Instagram')
            ->assertJsonPath('data.metadata.brand.logo_name', 'logo.png');

        $this->assertSame('#112233', $store->fresh()->metadata['brand']['colors']['accent']);
    }

    public function test_brand_requires_store_access(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$outsider] = $this->adminWithWorkspace('Other');

        $this->spa()->actingAs($outsider)
            ->putJson("/api/v1/stores/{$store->id}/brand", [
                'colors' => ['accent' => '#111111'],
            ])
            ->assertForbidden();
    }
}

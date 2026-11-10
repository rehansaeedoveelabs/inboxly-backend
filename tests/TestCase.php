<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sanctum treats requests from a stateful (SPA) origin as cookie/session authenticated.
     */
    protected function spa(): static
    {
        return $this->withHeaders([
            'Origin' => config('app.frontend_url'),
            'Referer' => config('app.frontend_url').'/',
            'Accept' => 'application/json',
        ]);
    }
}

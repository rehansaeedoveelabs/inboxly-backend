<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InfrastructureConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_phpunit_is_forced_onto_inboxly_testing_mysql_database(): void
    {
        $this->assertSame('testing', config('app.env'));
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('inboxly_testing', config('database.connections.mysql.database'));
        $this->assertSame('inboxly_testing', DB::selectOne('select database() as d')->d);
        $this->assertNotSame('inboxly', config('database.connections.mysql.database'));
        $this->assertStringNotContainsString('nexora', strtolower((string) config('database.connections.mysql.database')));
        $this->assertStringNotContainsString('datasyncai', strtolower((string) config('database.connections.mysql.database')));
    }

    public function test_application_uses_utc_and_spa_frontend_defaults(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('http://127.0.0.1:5173', config('app.frontend_url'));
        $this->assertContains('127.0.0.1:5173', config('sanctum.stateful'));
        $this->assertContains('http://127.0.0.1:5173', config('cors.allowed_origins'));
        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
    }

    public function test_ulid_primary_keys_and_foreign_keys_are_available(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'id'));
        $this->assertContains(Schema::getColumnType('users', 'id'), ['string', 'char']);

        $fk = collect(DB::select('
            select constraint_name
            from information_schema.table_constraints
            where table_schema = database()
              and table_name = ?
              and constraint_type = ?
        ', ['workspace_members', 'FOREIGN KEY']));

        $this->assertTrue($fk->isNotEmpty(), 'workspace_members should declare foreign keys');
    }

    public function test_csrf_cookie_endpoint_sets_xsrf_token_for_spa_origin(): void
    {
        $response = $this->spa()->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
        $response->assertCookie('XSRF-TOKEN');
    }

    public function test_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_session_cookie_defaults_are_safe_for_local_http_spa(): void
    {
        $this->assertSame('lax', config('session.same_site'));
        $this->assertTrue(config('session.http_only'));
        $this->assertFalse((bool) config('session.secure'));
        $this->assertSame(120, (int) config('session.lifetime'));
    }
}

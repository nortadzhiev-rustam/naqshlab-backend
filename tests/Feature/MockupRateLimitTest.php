<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MockupRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** Any 64-hex key; the endpoint 404s but the request still passes the throttle. */
    private const KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        // TrustProxies keeps its list statically, so it survives app refreshes.
        TrustProxies::at([]);
    }

    protected function tearDown(): void
    {
        TrustProxies::at([]);

        parent::tearDown();
    }

    /**
     * Drives the provider through config rather than the environment: reading
     * TRUSTED_PROXIES from .env would make these tests pass or fail depending
     * on the machine they run on.
     */
    private function bootWithTrustedProxies(?string $value): void
    {
        // A refreshed application opens a new :memory: connection, so the
        // schema RefreshDatabase built in setUp is gone with the old one.
        $this->refreshApplication();
        Artisan::call('migrate', ['--force' => true]);

        // The list is held statically, so clear whatever the boot just set.
        TrustProxies::at([]);
        config(['app.trusted_proxies' => $value]);

        (new AppServiceProvider($this->app))->boot();
    }

    private function poll(string $clientIp)
    {
        return $this->getJson('/api/mockups/'.self::KEY, [
            'x-api-key' => config('app.api_secret_key'),
            'X-Forwarded-For' => $clientIp,
        ]);
    }

    public function test_a_trusted_proxy_lets_the_real_client_ip_through(): void
    {
        $this->bootWithTrustedProxies('*');

        Route::middleware('api')->get('/_test-ip', fn (Request $request) => ['ip' => $request->ip()]);

        $this->getJson('/_test-ip', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertOk()
            ->assertJsonPath('ip', '203.0.113.9');
    }

    public function test_an_untrusted_forwarded_header_is_ignored(): void
    {
        $this->bootWithTrustedProxies(null);

        Route::middleware('api')->get('/_test-ip', fn (Request $request) => ['ip' => $request->ip()]);

        $this->getJson('/_test-ip', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertOk()
            ->assertJsonPath('ip', '127.0.0.1');
    }

    public function test_each_client_gets_its_own_bucket_behind_a_trusted_proxy(): void
    {
        $this->bootWithTrustedProxies('*');
        config(['app.mockup_rate_limit' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->poll('198.51.100.1')->assertNotFound();
        }

        $this->poll('198.51.100.1')->assertStatus(429);

        // A different customer must be unaffected by the first one's spending.
        $this->poll('198.51.100.2')->assertNotFound();
    }

    /**
     * The bug this fixes: with no trusted proxy every request carries the
     * frontend's address, so one customer's renders exhaust everyone's quota.
     */
    public function test_without_a_trusted_proxy_all_clients_share_one_bucket(): void
    {
        $this->bootWithTrustedProxies(null);
        config(['app.mockup_rate_limit' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->poll('198.51.100.1')->assertNotFound();
        }

        $this->poll('198.51.100.2')->assertStatus(429);
    }

    public function test_only_listed_proxies_are_trusted(): void
    {
        $this->bootWithTrustedProxies('10.9.9.9');

        Route::middleware('api')->get('/_test-ip', fn (Request $request) => ['ip' => $request->ip()]);

        // The test client connects from 127.0.0.1, which is not on the list.
        $this->getJson('/_test-ip', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertOk()
            ->assertJsonPath('ip', '127.0.0.1');
    }
}

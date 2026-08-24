<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        $this->configureTrustedProxies();
        $this->configureRateLimiting();
    }

    /**
     * Configured here rather than in bootstrap/app.php because that closure
     * runs before the config is loaded, so it cannot read an env-driven list.
     */
    private function configureTrustedProxies(): void
    {
        $proxies = config('app.trusted_proxies');

        if (blank($proxies)) {
            return;
        }

        TrustProxies::at(
            $proxies === '*'
                ? '*'
                : array_values(array_filter(array_map('trim', explode(',', (string) $proxies))))
        );
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('mockups', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('app.mockup_rate_limit'))
        )->by($request->ip() ?? 'unknown'));
    }
}

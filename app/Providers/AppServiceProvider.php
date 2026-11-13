<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateApiToken;
use App\Support\DirectUploads;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(function (): DirectUploads {
            /** @var array<string, mixed> $disk */
            $disk = config()->array('filesystems.disks.'.config()->string('meridian.assets_disk'));
            $publicEndpoint = config('meridian.direct_uploads.public_endpoint');

            return DirectUploads::fromConfig(
                $disk,
                is_string($publicEndpoint) ? $publicEndpoint : null,
                config()->integer('meridian.direct_uploads.part_mb') * 1024 * 1024,
            );
        });
    }

    public function boot(): void
    {
        // The broker calls from one address for every client, so logins are
        // limited per username instead.
        // Per token: the limiter runs after the token is checked.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)->by('api:'.AuthenticateApiToken::token($request)->id));
        // Single sign-on starts and answers, each counted on its own per address.
        RateLimiter::for('sso', fn (Request $request): Limit => Limit::perMinute(10)->by('sso:'.$request->ip()));
        RateLimiter::for('sso-callback', fn (Request $request): Limit => Limit::perMinute(20)->by('sso-callback:'.$request->ip()));
        RateLimiter::for('mqtt-logins', fn (Request $request): Limit => Limit::perMinute(30)->by('mqtt:'.$request->string('username')->value()));
    }
}

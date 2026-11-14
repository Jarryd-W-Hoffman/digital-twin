<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\AuthenticateScimToken;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // A twin definition is data: an empty unit or a padded message is
        // what the author wrote, so neither is trimmed or turned into null.
        $carriesDefinition = fn (Request $request): bool => is_array($request->input('definition'));
        $middleware->trimStrings(except: [$carriesDefinition]);
        $middleware->convertEmptyStringsToNull(except: [$carriesDefinition]);

        // The API token is checked before the rate limit, which counts per
        // token, and before route models are found, so a caller without one
        // can't tell which ids exist.
        $middleware->prependToPriorityList(ThrottleRequests::class, AuthenticateApiToken::class);
        $middleware->prependToPriorityList(ThrottleRequests::class, AuthenticateScimToken::class);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

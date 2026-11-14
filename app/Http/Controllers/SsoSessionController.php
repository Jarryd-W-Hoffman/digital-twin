<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StartSsoSignIn;
use App\Http\Requests\StartSsoSignInRequest;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Signing in through an organisation's identity provider: an email address
 * says which organisation, and the browser goes to its provider.
 */
final readonly class SsoSessionController
{
    public function create(): Response
    {
        return Inertia::render('sso/create');
    }

    public function store(StartSsoSignInRequest $request, StartSsoSignIn $action): SymfonyResponse
    {
        [$url, $flow] = $action->handle($request->connection(), $request->string('email')->value());

        $request->session()->put(StartSsoSignIn::SESSION, $flow);

        return Inertia::location($url);
    }
}

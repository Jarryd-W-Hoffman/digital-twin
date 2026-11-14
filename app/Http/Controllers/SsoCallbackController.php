<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\LinkSsoIdentity;
use App\Actions\SignInWithSso;
use App\Actions\StartSsoSignIn;
use App\Models\SsoConnection;
use App\Models\User;
use App\Support\OpenIdConnect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * Where an identity provider sends the browser back to: the answer is
 * checked against what this browser started, then the person is signed in,
 * or their account linked if that's what they started.
 */
final readonly class SsoCallbackController
{
    public function show(Request $request, OpenIdConnect $oidc, SignInWithSso $signIn, LinkSsoIdentity $link): RedirectResponse
    {
        /** @var array{connection: string, state: string, nonce: string, verifier: string, link: string|null}|null $flow */
        $flow = $request->session()->pull(StartSsoSignIn::SESSION);
        $linking = is_array($flow) && $flow['link'] !== null;
        $back = $linking ? to_route('user-sso.index') : to_route('sso.create');
        $field = $linking ? 'sso' : 'email';

        $state = $request->query('state');
        if (! is_array($flow) || ! is_string($state) || ! hash_equals($flow['state'], $state)) {
            return $back->withErrors([$field => __("The sign-in didn't start here, or took too long. Try again.")]);
        }

        $error = $request->query('error');
        if (is_string($error)) {
            return $back->withErrors([$field => __('The identity provider said no (:error).', ['error' => $error])]);
        }

        $connection = SsoConnection::query()->find($flow['connection']);
        $code = $request->query('code');
        if (! $connection instanceof SsoConnection || ! is_string($code)) {
            return $back->withErrors([$field => __('Single sign-on has been turned off for this organisation.')]);
        }

        try {
            $claims = $oidc->claims($connection, $code, route('sso.callback'), $flow['verifier'], $flow['nonce']);

            if ($linking) {
                $user = $request->user();
                abort_unless($user instanceof User && $user->id === $flow['link'], 403);
                $link->handle($user, $connection, $claims);
                Inertia::flash('toast', ['type' => 'success', 'message' => __("You can now sign in through :organisation's single sign-on.", ['organisation' => $connection->organisation->name])]);

                return to_route('user-sso.index');
            }

            $user = $signIn->handle($connection, $claims);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $back->withErrors([$field => $invalidArgumentException->getMessage()]);
        } catch (ValidationException $validationException) {
            return $back->withErrors($validationException->errors());
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['current_organisation_id' => $connection->organisation_id])->save();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}

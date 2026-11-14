<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\OrganisationRole;
use App\Models\User;
use App\Support\SsoRequirement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends someone working in an organisation that requires single sign-on,
 * who hasn't signed in through it this session, to do so first. Policies
 * refuse them anyway; this says why and how.
 */
final readonly class EnsureSsoSignIn
{
    public function __construct(private SsoRequirement $requirement) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        $organisation = $user->organisation();
        $role = $user->roleIn($organisation);

        if ($role instanceof OrganisationRole && $this->requirement->blocks($user, $organisation, $role)) {
            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return to_route('sso.required');
        }

        return $next($request);
    }
}

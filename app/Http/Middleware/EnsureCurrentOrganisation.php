<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\CreateOrganisation;
use App\Enums\OrganisationRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything in Meridian belongs to an organisation, so a signed-in user always
 * has a current one: their first membership, or a new personal organisation.
 */
final readonly class EnsureCurrentOrganisation
{
    public function __construct(private CreateOrganisation $organisations) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        if ($user->current_organisation_id === null || ! $user->roleIn($user->currentOrganisation()->firstOrFail()) instanceof OrganisationRole) {
            $first = $user->memberships()->value('organisation_id');

            if ($first === null) {
                $this->organisations->handle($user, __(":name's organisation", ['name' => $user->name]));
            } else {
                $user->forceFill(['current_organisation_id' => $first])->save();
            }
        }

        return $next($request);
    }
}

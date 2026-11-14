<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteScimUser;
use App\Actions\ProvisionScimUser;
use App\Actions\UpdateScimUser;
use App\Exceptions\ScimError;
use App\Http\Middleware\AuthenticateScimToken;
use App\Http\Resources\ScimUserResource;
use App\Models\ScimUser;
use App\Support\ScimAttributes;
use App\Support\ScimPatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SCIM 2.0 Users (RFC 7644) for the identity provider of one organisation:
 * the people it provisions, found by userName or externalId, and made,
 * changed, deactivated and deleted as it says.
 */
final readonly class ScimUserController
{
    private const int PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $users = AuthenticateScimToken::connection($request)->scimUsers()->with('user')->oldest();
        $filter = $request->query('filter');

        if (is_string($filter) && $filter !== '') {
            $match = preg_match('/^\s*(userName|externalId)\s+eq\s+"((?:[^"\\\\]|\\\\.)*)"\s*$/i', $filter, $parts);
            throw_unless($match === 1, ScimError::class, 400, __('Only userName eq "…" and externalId eq "…" filters are supported.'), 'invalidFilter');

            $value = stripslashes($parts[2]);
            mb_strtolower($parts[1]) === 'username'
                ? $users->where('user_name', mb_strtolower($value))
                : $users->where('external_id', $value);
        }

        $start = max(1, $request->integer('startIndex', 1));
        $count = min(self::PAGE, max(0, $request->integer('count', self::PAGE)));
        $total = $users->count();
        $page = $users->skip($start - 1)->take($count)->get();

        return $this->scim([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:ListResponse'],
            'totalResults' => $total,
            'startIndex' => $start,
            'itemsPerPage' => $page->count(),
            'Resources' => ScimUserResource::collection($page)->resolve($request),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->scim(new ScimUserResource($this->find($request, $id))->resolve($request));
    }

    public function store(Request $request, ProvisionScimUser $action): JsonResponse
    {
        $scimUser = $action->handle(AuthenticateScimToken::connection($request), ScimAttributes::from($this->body($request)));

        return $this->scim(new ScimUserResource($scimUser)->resolve($request), 201);
    }

    /**
     * PUT replaces the User; PATCH applies operations to it.
     */
    public function update(Request $request, string $id, UpdateScimUser $action): JsonResponse
    {
        $scimUser = $this->find($request, $id);
        $resource = $request->isMethod('PATCH')
            ? ScimPatch::apply(new ScimUserResource($scimUser)->resolve($request), $this->body($request))
            : $this->body($request);

        $scimUser = $action->handle($scimUser, ScimAttributes::from($resource));

        return $this->scim(new ScimUserResource($scimUser->refresh())->resolve($request));
    }

    public function destroy(Request $request, string $id, DeleteScimUser $action): Response
    {
        $action->handle($this->find($request, $id));

        return response()->noContent();
    }

    private function find(Request $request, string $id): ScimUser
    {
        $scimUser = AuthenticateScimToken::connection($request)->scimUsers()->with('user')->whereKey($id)->first();
        throw_unless($scimUser instanceof ScimUser, ScimError::class, 404, __('No provisioned user with that id.'));

        return $scimUser;
    }

    /**
     * @return array<mixed>
     */
    private function body(Request $request): array
    {
        $body = json_decode($request->getContent(), true);
        throw_unless(is_array($body), ScimError::class, 400, __('The body must be a JSON object.'), 'invalidSyntax');

        return $body;
    }

    /**
     * @param  array<mixed>  $body
     */
    private function scim(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, ['Content-Type' => 'application/scim+json']);
    }
}

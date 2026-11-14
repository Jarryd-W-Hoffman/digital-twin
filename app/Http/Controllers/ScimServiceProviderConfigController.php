<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * What Meridian's SCIM supports (RFC 7643, section 5): Users with PATCH and
 * simple filters, a bearer token, and no bulk, sorting or password changes.
 */
final readonly class ScimServiceProviderConfigController
{
    public function show(): JsonResponse
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => 100],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [[
                'type' => 'oauthbearertoken',
                'name' => 'Provisioning token',
                'description' => "The token made on the organisation's Single sign-on page.",
            ]],
        ], 200, ['Content-Type' => 'application/scim+json']);
    }
}

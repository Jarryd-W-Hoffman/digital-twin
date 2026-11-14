<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ScimUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A provisioned person as a SCIM User (RFC 7643, section 4.1).
 *
 * @property-read ScimUser $resource
 */
final class ScimUserResource extends JsonResource
{
    public const string SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';

    /**
     * SCIM resources aren't wrapped in data.
     *
     * @var string|null
     */
    public static $wrap;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $scimUser = $this->resource;
        $user = $scimUser->user;

        return [
            'schemas' => [self::SCHEMA],
            'id' => $scimUser->id,
            'externalId' => $scimUser->external_id,
            'userName' => $scimUser->user_name,
            'name' => ['formatted' => $user->name],
            'displayName' => $user->name,
            'emails' => [['value' => $user->email, 'type' => 'work', 'primary' => true]],
            'active' => $scimUser->active,
            'meta' => [
                'resourceType' => 'User',
                'created' => $scimUser->created_at->toIso8601String(),
                'lastModified' => $scimUser->updated_at->toIso8601String(),
                'location' => route('scim.users.show', $scimUser->id),
            ],
        ];
    }
}

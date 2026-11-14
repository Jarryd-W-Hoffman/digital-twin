<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A SCIM request refused, answered in the protocol's own error format
 * (RFC 7644, section 3.12) so the identity provider shows why.
 */
final class ScimError extends RuntimeException
{
    public function __construct(public readonly int $status, string $detail, public readonly ?string $scimType = null)
    {
        parent::__construct($detail);
    }

    public function render(): JsonResponse
    {
        $error = [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'status' => (string) $this->status,
            'detail' => $this->getMessage(),
        ];

        if ($this->scimType !== null) {
            $error['scimType'] = $this->scimType;
        }

        return response()->json($error, $this->status, ['Content-Type' => 'application/scim+json']);
    }
}

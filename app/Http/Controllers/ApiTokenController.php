<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Which token a caller is using and what it reaches, so an integration can
 * check its setup before it asks for anything else.
 */
final readonly class ApiTokenController
{
    public function show(Request $request): JsonResponse
    {
        $token = AuthenticateApiToken::token($request);

        return response()->json(['data' => [
            'name' => $token->name,
            'access' => $token->access->value,
            'expiresAt' => $token->expires_at?->toIso8601String(),
            'organisation' => ['id' => $token->organisation->id, 'name' => $token->organisation->name],
        ]]);
    }
}

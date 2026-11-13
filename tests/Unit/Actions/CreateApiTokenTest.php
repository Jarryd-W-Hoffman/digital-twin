<?php

declare(strict_types=1);

use App\Actions\CreateApiToken;
use App\Actions\RevokeApiToken;
use App\Enums\ApiTokenAccess;
use App\Enums\OrganisationRole;

it('makes a token, keeping only its hash, and revokes it', function (): void {
    $this->freezeSecond();
    [$user, $organisation] = member(OrganisationRole::Admin);

    [$token, $secret] = resolve(CreateApiToken::class)->handle($organisation, $user, 'CMMS', ApiTokenAccess::Read, now()->addDays(30));

    expect($secret)->toStartWith('mrd_')->toHaveLength(52)
        ->and($token->organisation_id)->toBe($organisation->id)
        ->and($token->created_by)->toBe($user->id)
        ->and($token->name)->toBe('CMMS')
        ->and($token->token_hash)->toBe(hash('sha256', $secret))
        ->and($token->hint)->toBe(mb_substr($secret, -4))
        ->and($token->expires_at?->equalTo(now()->addDays(30)))->toBeTrue()
        ->and($token->toArray())->not->toHaveKey('token_hash');

    [, $another] = resolve(CreateApiToken::class)->handle($organisation, $user, 'BI', ApiTokenAccess::Read, null);
    expect($another)->not->toBe($secret);

    resolve(RevokeApiToken::class)->handle($token);
    expect($token->fresh())->toBeNull()
        ->and($organisation->apiTokens()->count())->toBe(1);
});

<?php

declare(strict_types=1);

use App\Enums\ApiTokenAccess;
use App\Models\ApiToken;
use App\Models\Project;
use App\Models\Twin;
use App\Models\User;

test('to array', function (): void {
    expect(array_keys(ApiToken::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'organisation_id', 'created_by', 'name', 'hint', 'access', 'expires_at', 'last_used_at', 'created_at', 'updated_at']);
});

it('belongs to its organisation and remembers who made it, until they go', function (): void {
    $ana = User::factory()->create();
    $token = ApiToken::factory()->create(['created_by' => $ana->id]);

    expect($token->organisation->apiTokens->sole()->id)->toBe($token->id)
        ->and($token->creator?->id)->toBe($ana->id)
        ->and($token->access)->toBe(ApiTokenAccess::Read);

    $ana->delete();
    expect($token->refresh()->created_by)->toBeNull()
        ->and($token->creator)->toBeNull();
});

it('knows when it has expired', function (): void {
    $this->freezeSecond();

    expect(ApiToken::factory()->make()->isExpired())->toBeFalse()
        ->and(ApiToken::factory()->make(['expires_at' => now()->addSecond()])->isExpired())->toBeFalse()
        ->and(ApiToken::factory()->make(['expires_at' => now()->subSecond()])->isExpired())->toBeTrue();
});

it('shows only its last four characters', function (): void {
    expect(ApiToken::factory()->token('mrd_secret-wxyz')->make()->masked())->toBe('mrd_…wxyz');
});

it("reaches only its own organisation's projects and twins", function (): void {
    $token = ApiToken::factory()->create();

    expect($token->reaches(Project::factory()->for($token->organisation)->create()))->toBeTrue()
        ->and($token->reaches(Twin::factory()->for($token->organisation)->create()))->toBeTrue()
        ->and($token->reaches(Project::factory()->create()))->toBeFalse()
        ->and($token->reaches(Twin::factory()->create()))->toBeFalse();
});

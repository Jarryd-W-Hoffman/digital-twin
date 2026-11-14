<?php

declare(strict_types=1);

use App\Enums\ApiTokenAccess;
use App\Enums\OrganisationRole;
use App\Models\ApiToken;
use App\Models\User;
use Inertia\Inertia;

it("lists the organisation's tokens without their secrets", function (OrganisationRole $role, bool $manage): void {
    $this->freezeSecond();
    [$user, $organisation] = member($role);
    $ana = User::factory()->create(['name' => 'Ana']);
    $old = ApiToken::factory()->for($organisation)->token('mrd_old-token-abcd')->create(['name' => 'BI', 'created_by' => $ana->id, 'created_at' => now()->subDay(), 'expires_at' => now()->subHour(), 'last_used_at' => now()->subDays(2)]);
    $new = ApiToken::factory()->for($organisation)->create(['name' => 'CMMS']);
    ApiToken::factory()->create();

    $this->actingAs($user)->get(route('organisation.api-tokens.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('organisation/api-tokens')
            ->where('currentOrganisation', ['id' => $organisation->id, 'name' => $organisation->name])
            ->has('tokens', 2)
            ->where('tokens.0.id', $new->id)
            ->where('tokens.0.createdBy', null)
            ->where('tokens.0.expiresAt', null)
            ->where('tokens.0.expired', false)
            ->where('tokens.1', [
                'id' => $old->id,
                'name' => 'BI',
                'masked' => 'mrd_…abcd',
                'access' => 'Read only',
                'createdBy' => 'Ana',
                'createdAt' => now()->subDay()->toIso8601String(),
                'expiresAt' => now()->subHour()->toIso8601String(),
                'expired' => true,
                'lastUsedAt' => now()->subDays(2)->toIso8601String(),
            ])
            ->where('accessLevels', [['value' => 'read', 'label' => 'Read only'], ['value' => 'write', 'label' => 'Read and write']])
            ->where('baseUrl', url('/api/v1'))
            ->where('canManage', $manage));
})->with([[OrganisationRole::Admin, true], [OrganisationRole::Operator, false]]);

it('makes a token, shows it once, and revokes it', function (): void {
    $this->freezeSecond();
    [$user, $organisation] = member(OrganisationRole::Admin);

    $this->actingAs($user)->post(route('organisation.api-tokens.store'), ['name' => 'CMMS', 'access' => 'read', 'expires_in_days' => 90])
        ->assertRedirectToRoute('organisation.api-tokens.index')
        ->assertInertiaFlash('apiToken');
    $token = ApiToken::query()->sole();
    expect($token->organisation_id)->toBe($organisation->id)
        ->and($token->created_by)->toBe($user->id)
        ->and($token->access)->toBe(ApiTokenAccess::Read)
        ->and($token->expires_at?->equalTo(now()->addDays(90)))->toBeTrue();

    $this->actingAs($user)->post(route('organisation.api-tokens.store'), ['name' => 'BI', 'access' => 'read', 'expires_in_days' => ''])->assertRedirect();
    expect(ApiToken::query()->where('name', 'BI')->sole()->expires_at)->toBeNull();

    $this->actingAs($user)->delete(route('organisation.api-tokens.destroy', $token))
        ->assertRedirectToRoute('organisation.api-tokens.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'API token revoked.']);
    expect($token->fresh())->toBeNull();
});

it('flashes the secret that the API then accepts', function (): void {
    [$user] = member(OrganisationRole::Owner);

    $this->actingAs($user)->post(route('organisation.api-tokens.store'), ['name' => 'CMMS', 'access' => 'read']);
    $flashed = Inertia::getFlashed()['apiToken'] ?? null;

    expect($flashed)->toBeArray()
        ->and($flashed['token'] ?? null)->toStartWith('mrd_')->toHaveLength(52)
        ->and(ApiToken::query()->sole()->hint)->toBe(mb_substr((string) $flashed['token'], -4));
    $this->withToken((string) $flashed['token'])->getJson(route('api.v1.token.show'))->assertOk()->assertJsonPath('data.name', 'CMMS');
});

it('validates a new token', function (): void {
    [$user] = member(OrganisationRole::Admin);

    $this->actingAs($user)->post(route('organisation.api-tokens.store'), ['name' => str_repeat('x', 101), 'access' => 'admin', 'expires_in_days' => 7])
        ->assertSessionHasErrors(['name', 'access', 'expires_in_days']);
});

it('lets only admins and owners of its organisation make and revoke tokens', function (): void {
    [$operator, $organisation] = member(OrganisationRole::Operator);
    [$admin] = member(OrganisationRole::Admin);
    $token = ApiToken::factory()->for($organisation)->create();

    $this->actingAs($operator)->post(route('organisation.api-tokens.store'), ['name' => 'CMMS', 'access' => 'read'])->assertForbidden();
    $this->actingAs($operator)->delete(route('organisation.api-tokens.destroy', $token))->assertForbidden();
    $this->actingAs($admin)->delete(route('organisation.api-tokens.destroy', $token))->assertForbidden();
    expect($token->fresh())->not->toBeNull();
});

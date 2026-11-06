<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use Inertia\Inertia;

it('issues a token and shows it once', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.gateway-token.store', $twin))
        ->assertRedirectToRoute('twins.show', $twin)
        ->assertInertiaFlash('gatewayToken');

    $token = Inertia::getFlashed()['gatewayToken'] ?? null;
    expect($token)->toBeString()->toStartWith('mgw_')
        ->and($twin->refresh()->gateway_token_hash)->toBe(hash('sha256', (string) $token));
});

it('revokes the token', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $twin = twinIn($organisation);
    $twin->update(['gateway_token_hash' => str_repeat('a', 64)]);

    $this->actingAs($user)->delete(route('twins.gateway-token.destroy', $twin))
        ->assertRedirectToRoute('twins.show', $twin)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Gateway token revoked.']);

    expect($twin->refresh()->gateway_token_hash)->toBeNull();
});

it('keeps operators, viewers and outsiders from managing tokens', function (): void {
    [$operator, $organisation] = member(OrganisationRole::Operator);
    $twin = twinIn($organisation);
    [$outsider] = member();

    $this->actingAs($operator)->post(route('twins.gateway-token.store', $twin))->assertForbidden();
    $this->actingAs($operator)->delete(route('twins.gateway-token.destroy', $twin))->assertForbidden();
    $this->actingAs($outsider)->post(route('twins.gateway-token.store', $twin))->assertForbidden();

    expect($twin->refresh()->gateway_token_hash)->toBeNull();
});

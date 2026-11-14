<?php

declare(strict_types=1);

use App\Actions\StartSsoSignIn;
use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\SsoConnection;
use App\Models\SsoIdentity;
use App\Models\Twin;
use App\Models\User;
use App\Support\SsoRequirement;

/**
 * A member of an organisation that requires single sign-on, signed in with
 * a password.
 *
 * @return array{User, Organisation, SsoConnection}
 */
function requiredMember(OrganisationRole $role = OrganisationRole::Operator): array
{
    [$user, $organisation] = member($role);
    $connection = SsoConnection::factory()->for($organisation)->domains()->required()->create();

    return [$user, $organisation, $connection];
}

it('sends members signed in with a password to sign in through the provider first', function (): void {
    [$user, $organisation] = requiredMember();
    $twin = Twin::factory()->for($organisation)->create();

    $this->actingAs($user)->get(route('twins.show', $twin))->assertRedirect(route('sso.required'));
    expect(session('url.intended'))->toBe(route('twins.show', $twin));

    $this->post(route('projects.store'), ['name' => 'Sneaky'])->assertRedirect(route('sso.required'));
    expect(Project::query()->where('name', 'Sneaky')->exists())->toBeFalse();

    $this->get(route('sso.required'))->assertOk()->assertInertia(fn ($page) => $page->component('sso/required')
        ->where('organisation', $organisation->name)
        ->where('linked', false));
});

it("keeps them out of the organisation's things from elsewhere too", function (): void {
    [$user, $organisation] = requiredMember();
    $twin = Twin::factory()->for($organisation)->create();
    $elsewhere = Organisation::factory()->create();
    $elsewhere->memberships()->create(['user_id' => $user->id, 'role' => OrganisationRole::Owner]);
    $user->forceFill(['current_organisation_id' => $elsewhere->id])->save();

    $this->actingAs($user)->get(route('twins.show', $twin))->assertForbidden();
    $this->get(route('projects.index'))->assertOk();
});

it('lets owners in, and members once this session signed in through the provider', function (): void {
    [$owner, $organisation, $connection] = requiredMember(OrganisationRole::Owner);
    [$operator] = [User::factory()->create(['current_organisation_id' => $organisation->id])];
    $organisation->memberships()->create(['user_id' => $operator->id, 'role' => OrganisationRole::Operator]);

    $this->actingAs($owner)->get(route('projects.index'))->assertOk();
    $this->get(route('sso.required'))->assertRedirect(route('dashboard'));

    $this->actingAs($operator)->withSession([SsoRequirement::SESSION => [$connection->id]])->get(route('projects.index'))->assertOk();
});

it('goes through the provider, linking the account, and back to where they were', function (): void {
    fakeIdentityProvider();
    [$user, $organisation, $connection] = requiredMember();
    $twin = twinIn($organisation);

    $this->actingAs($user)->get(route('twins.show', $twin));
    $this->post(route('sso.required.store'))->assertRedirect();
    $flow = session(StartSsoSignIn::SESSION);
    expect($flow)->link->toBe($user->id)->then->toBe(route('twins.show', $twin));

    $this->withSession([StartSsoSignIn::SESSION => [...$flow, 'state' => 's', 'nonce' => 'nonce-from-the-browser']])
        ->get(route('sso.callback', ['state' => 's', 'code' => 'c']))
        ->assertRedirect(route('twins.show', $twin));

    expect(session(SsoRequirement::SESSION))->toBe([$connection->id])
        ->and($connection->identities()->sole()->user_id)->toBe($user->id);
    $this->get(route('twins.show', $twin))->assertOk();
    $this->get(route('sso.required'))->assertRedirect();
});

it('goes to the dashboard when nothing was asked for, and tells a linked member they will come straight back', function (): void {
    [$user, , $connection] = requiredMember();
    SsoIdentity::factory()->for($connection, 'connection')->for($user)->create();
    fakeIdentityProvider();

    $this->actingAs($user)->get(route('sso.required'))->assertInertia(fn ($page) => $page->where('linked', true));
    $this->post(route('sso.required.store'));

    expect(session(StartSsoSignIn::SESSION))->then->toBe(route('dashboard'));
});

it('brings refusals back to the page', function (): void {
    [$user, , $connection] = requiredMember();
    $flow = ['connection' => $connection->id, 'state' => 's', 'nonce' => 'n', 'verifier' => 'v', 'link' => $user->id, 'then' => route('dashboard')];

    $this->actingAs($user)->withSession([StartSsoSignIn::SESSION => $flow])
        ->get(route('sso.callback', ['state' => 's', 'error' => 'access_denied']))
        ->assertRedirect(route('sso.required'))
        ->assertSessionHasErrors(['sso' => 'The identity provider said no (access_denied).']);
});

it('counts signing in through the provider, and forgets it on unlinking', function (): void {
    fakeIdentityProvider();
    [$user, $organisation, $connection] = requiredMember();
    $identity = SsoIdentity::factory()->for($connection, 'connection')->for($user)->create(['subject' => 'ana-at-harbour']);
    $flow = ['connection' => $connection->id, 'state' => 's', 'nonce' => 'nonce-from-the-browser', 'verifier' => 'v', 'link' => null];

    $this->withSession([StartSsoSignIn::SESSION => $flow])->get(route('sso.callback', ['state' => 's', 'code' => 'c']))->assertRedirect(route('dashboard'));
    $this->get(route('projects.index'))->assertOk();

    $this->delete(route('user-sso.destroy', $identity));
    $this->get(route('projects.index'))->assertRedirect(route('sso.required'));
    expect($organisation->id)->toBe($connection->organisation_id);
});

it('has nothing to start without a provider', function (): void {
    [$user] = member(OrganisationRole::Viewer);

    $this->actingAs($user)->post(route('sso.required.store'))->assertNotFound();
});

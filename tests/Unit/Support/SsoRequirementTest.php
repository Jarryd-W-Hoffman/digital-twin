<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\SsoConnection;
use App\Models\User;
use App\Support\SsoRequirement;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

function withSession(): Store
{
    $session = new Store('test', new ArraySessionHandler(10));
    $request = Request::create('/');
    $request->setLaravelSession($session);

    app()->instance('request', $request);
    app()->instance('session.store', $session);

    return $session;
}

it('holds back members other than owners until this session signs in through the provider', function (OrganisationRole $role, bool $blocked): void {
    withSession();
    $connection = SsoConnection::factory()->required()->create();
    $requirement = resolve(SsoRequirement::class);

    expect($requirement->blocks(User::factory()->create(), $connection->organisation, $role))->toBe($blocked);

    $requirement->verify($connection);
    $requirement->verify($connection);

    expect($requirement->blocks(User::factory()->create(), $connection->organisation, $role))->toBeFalse()
        ->and(request()->session()->get(SsoRequirement::SESSION))->toBe([$connection->id]);

    $requirement->forget($connection);

    expect($requirement->blocks(User::factory()->create(), $connection->organisation, $role))->toBe($blocked);
})->with([
    [OrganisationRole::Owner, false],
    [OrganisationRole::Admin, true],
    [OrganisationRole::Operator, true],
    [OrganisationRole::Viewer, true],
]);

it('holds nobody back without a required provider, or outside a request', function (): void {
    $user = User::factory()->create();
    $requirement = resolve(SsoRequirement::class);
    $required = SsoConnection::factory()->required()->create()->organisation;

    expect($requirement->blocks($user, $required, OrganisationRole::Viewer))->toBeFalse();

    withSession();

    expect($requirement->blocks($user, Organisation::factory()->create(), OrganisationRole::Viewer))->toBeFalse()
        ->and($requirement->blocks($user, SsoConnection::factory()->create()->organisation, OrganisationRole::Viewer))->toBeFalse()
        ->and($requirement->blocks($user, $required, OrganisationRole::Viewer))->toBeTrue();
});

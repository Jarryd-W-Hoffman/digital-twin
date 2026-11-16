<?php

declare(strict_types=1);

use App\Enums\Feature;
use App\Enums\OrganisationRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Http\Request;

it('shares app name from config', function (): void {
    $middleware = new HandleInertiaRequests();

    $request = Request::create('/', 'GET');

    $shared = $middleware->share($request);

    expect($shared)->toHaveKey('name')
        ->and($shared['name'])->toBe(config('app.name'));
});

it('shares null user when guest', function (): void {
    $middleware = new HandleInertiaRequests();

    $request = Request::create('/', 'GET');

    $shared = $middleware->share($request);

    expect($shared)->toHaveKey('auth')
        ->and($shared['auth'])->toHaveKey('user')
        ->and($shared['auth']['user'])->toBeNull();
});

it('shares authenticated user data', function (): void {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);

    $middleware = new HandleInertiaRequests();

    $request = Request::create('/', 'GET');
    $request->setUserResolver(fn () => $user);

    $shared = $middleware->share($request);

    expect($shared['auth']['user'])->not->toBeNull()
        ->and($shared['auth']['user']->id)->toBe($user->id)
        ->and($shared['auth']['user']->name)->toBe('Test User')
        ->and($shared['auth']['user']->email)->toBe('test@example.com');
});

it('defaults sidebarOpen to true when no cookie', function (): void {
    $middleware = new HandleInertiaRequests();

    $request = Request::create('/', 'GET');

    $shared = $middleware->share($request);

    expect($shared)->toHaveKey('sidebarOpen')
        ->and($shared['sidebarOpen'])->toBeTrue();
});

it('sets sidebarOpen to true when cookie is true', function (): void {
    $middleware = new HandleInertiaRequests();

    $request = Request::create('/', 'GET');
    $request->cookies->set('sidebar_state', 'true');

    $shared = $middleware->share($request);

    expect($shared['sidebarOpen'])->toBeTrue();
});

it('sets sidebarOpen to false when cookie is false', function (): void {
    $middleware = new HandleInertiaRequests();

    $request = Request::create('/', 'GET');
    $request->cookies->set('sidebar_state', 'false');

    $shared = $middleware->share($request);

    expect($shared['sidebarOpen'])->toBeFalse();
});

it('includes parent shared data', function (): void {
    $middleware = new HandleInertiaRequests();

    $request = Request::create('/', 'GET');

    $shared = $middleware->share($request);

    // Parent Inertia middleware shares 'errors' by default
    expect($shared)->toHaveKey('errors');
});

it('shares no organisation for guests', function (): void {
    $shared = new HandleInertiaRequests()->share(Request::create('/', 'GET'));

    expect($shared['organisation']())->toBeNull();
});

it('shares the current organisation, the role and every membership', function (): void {
    [$user, $organisation] = member(OrganisationRole::Operator);
    [, $other] = member();
    $other->memberships()->create(['user_id' => $user->id, 'role' => OrganisationRole::Viewer]);
    $request = Request::create('/', 'GET');
    $request->setUserResolver(fn () => $user);

    $shared = new HandleInertiaRequests()->share($request);

    expect($shared['organisation']())->toBe([
        'current' => ['id' => $organisation->id, 'name' => $organisation->name, 'role' => 'operator', 'features' => []],
        'all' => [
            ['id' => $organisation->id, 'name' => $organisation->name],
            ['id' => $other->id, 'name' => $other->name],
        ],
    ]);
});

it('shares the features on for the current organisation, and only those the server offers', function (?string $nodeOdm, array $shared): void {
    config(['meridian.photos.nodeodm_url' => $nodeOdm]);
    [$user, $organisation] = member();
    $organisation->update(['features' => [Feature::PhotoProcessing]]);
    $request = Request::create('/', 'GET');
    $request->setUserResolver(fn () => $user);

    expect(new HandleInertiaRequests()->share($request)['organisation']()['current']['features'] ?? null)->toBe($shared);
})->with([
    'offered' => ['http://nodeodm:3000', ['photo-processing']],
    'not offered' => [null, []],
]);

it('shares no current organisation when the user has none', function (): void {
    $user = User::factory()->create();
    $request = Request::create('/', 'GET');
    $request->setUserResolver(fn () => $user);

    expect(new HandleInertiaRequests()->share($request)['organisation']())->toBe(['current' => null, 'all' => []]);
});

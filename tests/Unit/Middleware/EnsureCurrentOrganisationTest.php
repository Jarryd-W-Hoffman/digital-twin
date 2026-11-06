<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Http\Middleware\EnsureCurrentOrganisation;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

function passThrough(User $user): void
{
    $request = Request::create('/twins');
    $request->setUserResolver(fn (): User => $user);

    resolve(EnsureCurrentOrganisation::class)->handle($request, fn (): Response => new Response('ok'));
}

it('gives a user with no organisation a personal one', function (): void {
    $user = User::factory()->create(['name' => 'Ada']);

    passThrough($user);

    expect($user->refresh()->organisation()->name)->toBe("Ada's organisation")
        ->and($user->roleIn($user->organisation()))->toBe(OrganisationRole::Owner);
});

it('falls back to a membership when the current organisation is not theirs', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $user->forceFill(['current_organisation_id' => Organisation::factory()->create()->id])->save();

    passThrough($user);

    expect($user->refresh()->current_organisation_id)->toBe($organisation->id);
});

it('leaves a valid current organisation alone', function (): void {
    [$user, $organisation] = member();

    passThrough($user);

    expect($user->refresh()->current_organisation_id)->toBe($organisation->id)
        ->and(Organisation::query()->count())->toBe(1);
});

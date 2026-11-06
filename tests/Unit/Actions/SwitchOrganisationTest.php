<?php

declare(strict_types=1);

use App\Actions\SwitchOrganisation;
use App\Enums\OrganisationRole;
use App\Models\Organisation;
use Illuminate\Auth\Access\AuthorizationException;

it('switches to an organisation the user belongs to', function (): void {
    [$user] = member();
    $other = Organisation::factory()->create();
    $other->memberships()->create(['user_id' => $user->id, 'role' => OrganisationRole::Viewer]);

    resolve(SwitchOrganisation::class)->handle($user, $other);

    expect($user->refresh()->current_organisation_id)->toBe($other->id);
});

it('refuses an organisation the user is not in', function (): void {
    [$user] = member();

    resolve(SwitchOrganisation::class)->handle($user, Organisation::factory()->create());
})->throws(AuthorizationException::class);

<?php

declare(strict_types=1);

use App\Actions\CreateOrganisation;
use App\Enums\OrganisationRole;
use App\Models\User;

it('creates an organisation the user owns and switches to it', function (): void {
    $user = User::factory()->create();

    $organisation = resolve(CreateOrganisation::class)->handle($user, 'Harbour Ops');

    expect($organisation->name)->toBe('Harbour Ops')
        ->and($organisation->slug)->toStartWith('harbour-ops-')
        ->and($user->roleIn($organisation))->toBe(OrganisationRole::Owner)
        ->and($user->refresh()->current_organisation_id)->toBe($organisation->id);
});

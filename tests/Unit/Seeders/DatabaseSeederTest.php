<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('seeds an organisation with every role and a project of sample twins', function (): void {
    $this->seed(DatabaseSeeder::class);

    $organisation = Organisation::query()->sole();

    expect($organisation->name)->toBe('Northshore Logistics')
        ->and($organisation->projects()->sole()->name)->toBe('Pilot sites')
        ->and($organisation->projects()->sole()->twins()->pluck('name')->all())->toEqualCanonicalizing(['Northshore Cold Chain Hub', 'Harbour Depot'])
        ->and(User::query()->where('email', 'test@example.com')->sole()->roleIn($organisation))->toBe(OrganisationRole::Owner)
        ->and(User::query()->where('email', 'operator@example.com')->sole()->roleIn($organisation))->toBe(OrganisationRole::Operator)
        ->and(User::query()->where('email', 'viewer@example.com')->sole()->current_organisation_id)->toBe($organisation->id)
        ->and($organisation->assetTypes()->orderBy('slug')->pluck('slug')->all())->toBe(['jockey-pump', 'water-tank']);
});

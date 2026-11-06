<?php

declare(strict_types=1);

use App\Actions\CreateTwin;
use App\Models\Project;
use App\Support\SampleTwins;

it('creates a twin with its first version', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $twin = resolve(CreateTwin::class)->handle($project, $user, 'Depot', 'Cold store', resolve(SampleTwins::class)->definition('harbour-depot'));

    expect($twin->organisation_id)->toBe($organisation->id)
        ->and($twin->project_id)->toBe($project->id)
        ->and($twin->description)->toBe('Cold store')
        ->and($twin->latestVersion?->number)->toBe(1)
        ->and($twin->latestVersion?->note)->toBe('Created');
});

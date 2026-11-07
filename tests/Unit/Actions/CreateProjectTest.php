<?php

declare(strict_types=1);

use App\Actions\CreateProject;

it('creates a project in the organisation', function (): void {
    [, $organisation] = member();

    $project = resolve(CreateProject::class)->handle($organisation, 'Harbour', 'Quay scans');

    expect($project->organisation_id)->toBe($organisation->id)
        ->and($project->name)->toBe('Harbour')
        ->and($project->description)->toBe('Quay scans')
        ->and(resolve(CreateProject::class)->handle($organisation, 'Bare')->description)->toBeNull();
});

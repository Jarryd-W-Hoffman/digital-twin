<?php

declare(strict_types=1);

use App\Models\Project;

test('to array', function (): void {
    expect(array_keys(Project::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'organisation_id', 'name', 'description', 'created_at', 'updated_at']);
});

it('belongs to an organisation and stores its uploads under its own prefix', function (): void {
    [, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    expect($project->organisation->is($organisation))->toBeTrue()
        ->and($organisation->projects->sole()->is($project))->toBeTrue()
        ->and($project->storagePrefix())->toBe('projects/'.$project->id);
});

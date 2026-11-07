<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\Project;
use App\Models\ProjectAsset;
use Illuminate\Support\Facades\Storage;

it('lists the projects in the current organisation only', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    ProjectAsset::factory()->for($twin->project)->create();
    [, $other] = member();
    Project::factory()->for($other)->create();

    $this->actingAs($user)->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/index')
            ->has('projects', 1)
            ->where('projects.0.id', $twin->project_id)
            ->where('projects.0.twins', 1)
            ->where('projects.0.assets', 1)
            ->where('canCreate', false));
});

it('sends the dashboard to the projects', function (): void {
    [$user] = member();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirectToRoute('projects.index');
});

it('creates a project', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);

    $this->actingAs($user)->get(route('projects.create'))->assertOk()->assertInertia(fn ($page) => $page->component('projects/create'));
    $response = $this->actingAs($user)->post(route('projects.store'), ['name' => 'Harbour', 'description' => '']);

    $project = $organisation->projects()->sole();
    $response->assertRedirectToRoute('projects.show', $project);
    expect($project->name)->toBe('Harbour')->and($project->description)->toBeNull();
    $this->actingAs($user)->post(route('projects.store'), ['name' => 'Quay', 'description' => 'Scans']);
    expect($organisation->projects()->where('name', 'Quay')->sole()->description)->toBe('Scans');
});

it('needs a name', function (): void {
    [$user] = member();

    $this->actingAs($user)->post(route('projects.store'), ['name' => ''])->assertSessionHasErrors('name');
});

it('keeps operators and viewers from creating or deleting projects', function (OrganisationRole $role): void {
    [$user, $organisation] = member($role);
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->get(route('projects.create'))->assertForbidden();
    $this->actingAs($user)->post(route('projects.store'), ['name' => 'X'])->assertForbidden();
    $this->actingAs($user)->delete(route('projects.destroy', $project))->assertForbidden();
})->with([OrganisationRole::Operator, OrganisationRole::Viewer]);

it('shows a project with its twins, library and abilities', function (OrganisationRole $role, bool $update): void {
    [$user, $organisation] = member($role);
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create();

    $this->actingAs($user)->get(route('projects.show', $twin->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('projects/show')
            ->where('project.id', $twin->project_id)
            ->where('twins.0.id', $twin->id)
            ->where('twins.0.version', 1)
            ->where('assets.0.id', $asset->id)
            ->where('assets.0.kind', 'model')
            ->where('assets.0.bytes', 1024)
            ->where('assets.0.status', 'ready')
            ->where('directUploads', false)
            ->where('can.update', $update));
})->with([
    [OrganisationRole::Owner, true],
    [OrganisationRole::Viewer, false],
]);

it('hides projects from other organisations', function (): void {
    $project = Project::factory()->create();
    [$outsider] = member();

    $this->actingAs($outsider)->get(route('projects.show', $project))->assertForbidden();
    $this->actingAs($outsider)->delete(route('projects.destroy', $project))->assertForbidden();
});

it('deletes a project', function (): void {
    Storage::fake('local');
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->delete(route('projects.destroy', $twin->project))
        ->assertRedirectToRoute('projects.index')
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Project deleted.']);

    expect(Project::query()->count())->toBe(0)->and($twin->fresh())->toBeNull();
});

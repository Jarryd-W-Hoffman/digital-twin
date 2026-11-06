<?php

declare(strict_types=1);

use App\Actions\DeleteProject;
use App\Models\ProjectAsset;
use App\Models\Twin;
use Illuminate\Support\Facades\Storage;

it('deletes the project, its twins and its library files, wherever they were written', function (): void {
    Storage::fake('local');
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $project = $twin->project;
    $current = ProjectAsset::factory()->for($project)->create(['directory' => $project->storagePrefix().'/assets/a']);
    $legacy = ProjectAsset::factory()->for($project)->create(['directory' => 'twins/'.$twin->id.'/assets/b']);
    Storage::disk('local')->put($current->directory.'/model.glb', 'x');
    Storage::disk('local')->put($legacy->directory.'/model.glb', 'x');
    Storage::disk('local')->put('projects/other/assets/c/model.glb', 'kept');

    resolve(DeleteProject::class)->handle($project);

    expect($project->fresh())->toBeNull()
        ->and(Twin::query()->count())->toBe(0)
        ->and(ProjectAsset::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe(['projects/other/assets/c/model.glb']);
});

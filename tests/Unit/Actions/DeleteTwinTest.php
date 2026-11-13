<?php

declare(strict_types=1);

use App\Actions\DeleteTwin;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\ProjectAsset;
use Illuminate\Support\Facades\Storage;

it("deletes the twin with its issues' photos, and leaves the project library alone", function (): void {
    Storage::fake('local');
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create();
    Storage::disk('local')->put($asset->directory.'/model.glb', 'x');
    $issue = Issue::factory()->for($twin)->create();
    $comment = IssueComment::factory()->for($issue)->create(['photo' => $issue->storagePrefix().'/crack.jpg']);
    Storage::disk('local')->put((string) $comment->photo, 'x');

    resolve(DeleteTwin::class)->handle($twin);

    expect($twin->exists)->toBeFalse()
        ->and($asset->fresh())->not->toBeNull()
        ->and($issue->fresh())->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([$asset->directory.'/model.glb']);
});

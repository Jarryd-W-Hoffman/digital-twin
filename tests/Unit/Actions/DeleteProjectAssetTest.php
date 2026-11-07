<?php

declare(strict_types=1);

use App\Actions\AddAssetToTwin;
use App\Actions\DeleteProjectAsset;
use App\Actions\StoreProjectAsset;
use App\Models\ProjectAsset;
use App\Models\Twin;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('takes the upload out of every twin that shows it, then deletes its files', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $sibling = Twin::factory()->inProject($twin->project)->create();
    $twin->versions()->first()?->replicate()->fill(['twin_id' => $sibling->id])->save();
    $asset = resolve(StoreProjectAsset::class)->handle($twin->project, glbUpload(), 'Pump');
    resolve(AddAssetToTwin::class)->handle($twin, $user, $asset);
    resolve(AddAssetToTwin::class)->handle($sibling, $user, $asset);

    resolve(DeleteProjectAsset::class)->handle($asset, $user);

    expect(ProjectAsset::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($twin->latestVersion()->sole()->note)->toBe('Removed Pump')
        ->and($sibling->latestVersion()->sole()->definition['models'])->toBe([]);
});

it('saves no version for twins that never showed it', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $asset = ProjectAsset::factory()->for($twin->project)->create();

    resolve(DeleteProjectAsset::class)->handle($asset, $user);

    expect($twin->versions()->count())->toBe(1);
});

it('also removes an original still waiting to be processed', function (): void {
    $asset = ProjectAsset::factory()->processing()->create(['original_name' => 'scan.zip']);
    Storage::disk('local')->put($asset->sourceKey(), 'zip');

    resolve(DeleteProjectAsset::class)->handle($asset, null);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

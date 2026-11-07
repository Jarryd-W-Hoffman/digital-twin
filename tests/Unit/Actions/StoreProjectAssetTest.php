<?php

declare(strict_types=1);

use App\Actions\StoreProjectAsset;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Jobs\ConvertUpload;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Storage::fake('local'));

it('stores a model in the library without touching any twin', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);

    $asset = resolve(StoreProjectAsset::class)->handle($twin->project, glbUpload(), 'Pump skid');

    expect($asset->kind)->toBe(ProjectAssetKind::Model)
        ->and($asset->project_id)->toBe($twin->project_id)
        ->and($asset->directory)->toBe($twin->project->storagePrefix().'/assets/'.$asset->id)
        ->and($asset->entry)->toBe('model.glb')
        ->and($asset->bytes)->toBe(12)
        ->and($asset->original_name)->toBe('pump.glb')
        ->and(Storage::disk('local')->exists($asset->directory.'/model.glb'))->toBeTrue()
        ->and($twin->versions()->count())->toBe(1);
});

it('stores a tileset', function (): void {
    $project = Project::factory()->create();

    $asset = resolve(StoreProjectAsset::class)->handle($project, tilesetZip(['tileset.json' => tilesetJson(), 'tiles/0.glb' => 'x']), 'Laser scan');

    expect($asset->kind)->toBe(ProjectAssetKind::Tileset)
        ->and($asset->entry)->toBe('tileset.json')
        ->and($asset->files)->toBe(2);
});

it('refuses files that are not GLB', function (string $bytes): void {
    $project = Project::factory()->create();

    expect(fn () => resolve(StoreProjectAsset::class)->handle($project, glbUpload(bytes: $bytes), 'Fake'))
        ->toThrow(ValidationException::class, 'not a glTF 2.0 binary')
        ->and($project->assets()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'short' => ['glTF'],
    'wrong magic' => ['GLTF'.pack('VV', 2, 12)],
    'version 1' => ['glTF'.pack('VV', 1, 12)],
    'wrong length' => ['glTF'.pack('VV', 2, 99)],
]);

it('cleans up the files when saving fails', function (): void {
    $unsaved = Project::factory()->make();

    expect(fn () => resolve(StoreProjectAsset::class)->handle($unsaved, glbUpload(), 'Pump'))->toThrow(QueryException::class)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('keeps a point cloud as uploaded and queues its conversion', function (): void {
    Queue::fake();
    [, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $asset = resolve(StoreProjectAsset::class)->handle($project, UploadedFile::fake()->createWithContent('Quay.LAZ', 'LASF'), 'Quay', ['epsg' => 2193]);

    expect($asset->kind)->toBe(ProjectAssetKind::PointCloud)
        ->and($asset->status)->toBe(ProjectAssetStatus::Processing)
        ->and($asset->options)->toBe(['epsg' => 2193])
        ->and(Storage::disk('local')->get($asset->sourceKey()))->toBe('LASF');
    Queue::assertPushed(ConvertUpload::class, fn (ConvertUpload $job): bool => $job->asset->is($asset) && $job->connection === 'conversions');
});

it('keeps a DEM as uploaded and queues its conversion to terrain', function (): void {
    Queue::fake();
    [, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $asset = resolve(StoreProjectAsset::class)->handle($project, UploadedFile::fake()->createWithContent('Site DEM.tif', "II*\0"), 'Site', ['epsg' => 2193]);

    expect($asset->kind)->toBe(ProjectAssetKind::Terrain)
        ->and($asset->status)->toBe(ProjectAssetStatus::Processing)
        ->and($asset->options)->toBe(['epsg' => 2193]);
    Queue::assertPushed(ConvertUpload::class, fn (ConvertUpload $job): bool => $job->asset->is($asset));
});

it('ignores conversion options for other kinds', function (): void {
    [, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    expect(resolve(StoreProjectAsset::class)->handle($project, glbUpload(), 'Pump', ['epsg' => 2193])->options)->toBeNull();
});

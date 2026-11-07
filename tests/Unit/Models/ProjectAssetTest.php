<?php

declare(strict_types=1);

use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Models\ProjectAsset;

test('to array', function (): void {
    expect(array_keys(ProjectAsset::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'project_id', 'kind', 'name', 'original_name', 'directory', 'entry', 'bytes', 'files', 'created_at', 'updated_at', 'status', 'error', 'upload_id', 'options', 'progress', 'log', 'crs']);
});

it('belongs to its project and loads from a route that names only itself', function (): void {
    $asset = ProjectAsset::factory()->create(['kind' => ProjectAssetKind::Tileset, 'entry' => 'tileset.json']);

    expect($asset->kind)->toBe(ProjectAssetKind::Tileset)
        ->and($asset->project->assets->sole()->is($asset))->toBeTrue()
        ->and($asset->url())->toBe(sprintf('/assets/%s/files/tileset.json', $asset->id));
});

it('knows whether it is ready and where a direct upload lands', function (): void {
    $asset = ProjectAsset::factory()->uploading()->create(['original_name' => 'scan.zip']);

    expect($asset->status)->toBe(ProjectAssetStatus::Uploading)
        ->and($asset->isReady())->toBeFalse()
        ->and($asset->sourceKey())->toBe('uploads/'.$asset->id.'/scan.zip')
        ->and(ProjectAsset::factory()->create()->isReady())->toBeTrue();
});

it('tells models from tileset archives by extension', function (string $filename, ProjectAssetKind $kind): void {
    expect(ProjectAssetKind::fromFilename($filename))->toBe($kind);
})->with([
    ['scan.ZIP', ProjectAssetKind::Tileset],
    ['pump.glb', ProjectAssetKind::Model],
    ['noextension', ProjectAssetKind::Model],
    ['quay.las', ProjectAssetKind::PointCloud],
    ['quay.LAZ', ProjectAssetKind::PointCloud],
    ['room.e57', ProjectAssetKind::PointCloud],
]);

it('shows point clouds and tilesets as tilesets', function (): void {
    expect(ProjectAssetKind::Tileset->isTileset())->toBeTrue()
        ->and(ProjectAssetKind::PointCloud->isTileset())->toBeTrue()
        ->and(ProjectAssetKind::Model->isTileset())->toBeFalse();
});

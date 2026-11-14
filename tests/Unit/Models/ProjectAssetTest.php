<?php

declare(strict_types=1);

use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Models\ProjectAsset;

test('to array', function (): void {
    expect(array_keys(ProjectAsset::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'project_id', 'kind', 'name', 'original_name', 'directory', 'entry', 'bytes', 'files', 'created_at', 'updated_at', 'status', 'error', 'upload_id', 'options', 'progress', 'log', 'crs', 'details']);
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

it('tells models from tileset archives by extension', function (string $filename, ProjectAssetKind $kind, ?string $as = null): void {
    expect(ProjectAssetKind::fromFilename($filename, $as))->toBe($kind);
})->with([
    ['scan.ZIP', ProjectAssetKind::Tileset],
    ['pump.glb', ProjectAssetKind::Model],
    ['noextension', ProjectAssetKind::Model],
    ['quay.las', ProjectAssetKind::PointCloud],
    ['quay.LAZ', ProjectAssetKind::PointCloud],
    ['room.e57', ProjectAssetKind::PointCloud],
    ['site.tif', ProjectAssetKind::Terrain],
    ['site.TIFF', ProjectAssetKind::Terrain],
    ['grid.asc', ProjectAssetKind::Terrain],
    ['shed.IFC', ProjectAssetKind::Bim],
    ['parcels.geojson', ProjectAssetKind::Vector],
    ['parcels.json', ProjectAssetKind::Vector],
    ['route.KML', ProjectAssetKind::Vector],
    ['route.kmz', ProjectAssetKind::Vector],
    ['plan.dxf', ProjectAssetKind::Vector],
    ['survey.gpkg', ProjectAssetKind::Vector],
    'a GeoTIFF as imagery' => ['ortho.tif', ProjectAssetKind::Imagery, 'imagery'],
    'a zipped shapefile' => ['parcels.zip', ProjectAssetKind::Vector, 'vector'],
    'a zipped photogrammetry export' => ['survey.zip', ProjectAssetKind::Mesh, 'mesh'],
    'an OBJ mesh' => ['survey.OBJ', ProjectAssetKind::Mesh],
    'a mesh is only a zip or an OBJ' => ['ortho.tif', ProjectAssetKind::Terrain, 'mesh'],
    'imagery is only a GeoTIFF' => ['grid.asc', ProjectAssetKind::Terrain, 'imagery'],
    'a shapefile is only a zip' => ['ortho.tif', ProjectAssetKind::Terrain, 'vector'],
]);

it('shows point clouds, meshes and tilesets as tilesets', function (): void {
    expect(ProjectAssetKind::Tileset->isTileset())->toBeTrue()
        ->and(ProjectAssetKind::PointCloud->isTileset())->toBeTrue()
        ->and(ProjectAssetKind::Mesh->isTileset())->toBeTrue()
        ->and(ProjectAssetKind::Mesh->isConverted())->toBeTrue()
        ->and(ProjectAssetKind::Model->isTileset())->toBeFalse()
        ->and(ProjectAssetKind::Terrain->isTileset())->toBeFalse()
        ->and(ProjectAssetKind::Bim->isTileset())->toBeFalse();
});

it('converts point clouds, DEMs and IFC models on the queue', function (): void {
    expect(ProjectAssetKind::PointCloud->isConverted())->toBeTrue()
        ->and(ProjectAssetKind::Bim->isConverted())->toBeTrue()
        ->and(ProjectAssetKind::Terrain->isConverted())->toBeTrue()
        ->and(ProjectAssetKind::Vector->isConverted())->toBeTrue()
        ->and(ProjectAssetKind::Imagery->isConverted())->toBeTrue()
        ->and(ProjectAssetKind::Tileset->isConverted())->toBeFalse()
        ->and(ProjectAssetKind::Model->isConverted())->toBeFalse();
});

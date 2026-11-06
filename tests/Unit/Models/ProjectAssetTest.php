<?php

declare(strict_types=1);

use App\Enums\ProjectAssetKind;
use App\Models\ProjectAsset;

test('to array', function (): void {
    expect(array_keys(ProjectAsset::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'project_id', 'kind', 'name', 'original_name', 'directory', 'entry', 'bytes', 'files', 'created_at', 'updated_at']);
});

it('belongs to its project and loads from a route that names only itself', function (): void {
    $asset = ProjectAsset::factory()->create(['kind' => ProjectAssetKind::Tileset, 'entry' => 'tileset.json']);

    expect($asset->kind)->toBe(ProjectAssetKind::Tileset)
        ->and($asset->project->assets->sole()->is($asset))->toBeTrue()
        ->and($asset->url())->toBe(sprintf('/assets/%s/files/tileset.json', $asset->id));
});

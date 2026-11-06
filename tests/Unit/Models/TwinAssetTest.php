<?php

declare(strict_types=1);

use App\Enums\TwinAssetKind;
use App\Models\TwinAsset;

test('to array', function (): void {
    expect(array_keys(TwinAsset::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'twin_id', 'kind', 'name', 'original_name', 'entry', 'bytes', 'files', 'created_at', 'updated_at']);
});

it('knows where its files live and how the viewer loads them', function (): void {
    $asset = TwinAsset::factory()->create(['kind' => TwinAssetKind::Tileset, 'entry' => 'tileset.json']);

    expect($asset->kind)->toBe(TwinAssetKind::Tileset)
        ->and($asset->directory())->toBe(sprintf('twins/%s/assets/%s', $asset->twin_id, $asset->id))
        ->and($asset->url())->toBe(sprintf('/twins/%s/assets/%s/files/tileset.json', $asset->twin_id, $asset->id));
});

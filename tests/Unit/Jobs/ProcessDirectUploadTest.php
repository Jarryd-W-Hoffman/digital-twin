<?php

declare(strict_types=1);

use App\Actions\ProcessProjectAssetFile;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Jobs\ProcessDirectUpload;
use App\Models\ProjectAsset;
use App\Support\DirectUploads;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('s3'));

it('unpacks the uploaded file into the library and removes the original', function (): void {
    $zip = tilesetZip(['tileset.json' => tilesetJson(), 'tiles/0.glb' => 'g']);
    $sent = fakeDirectUploads(['GetObject' => new Result(['Body' => Utils::streamFor((string) file_get_contents($zip->getRealPath()))])]);
    $asset = ProjectAsset::factory()->processing()->create(['kind' => ProjectAssetKind::Tileset, 'original_name' => 'scan.zip']);

    dispatch_sync(new ProcessDirectUpload($asset));

    $asset->refresh();
    expect($asset->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->entry)->toBe('tileset.json')
        ->and($asset->files)->toBe(2)
        ->and(Storage::disk('s3')->exists($asset->directory.'/tiles/0.glb'))->toBeTrue()
        ->and(array_map(fn (CommandInterface $command): string => $command->getName(), $sent->getArrayCopy()))->toBe(['GetObject', 'DeleteObject'])
        ->and($sent[1]['Key'])->toBe($asset->sourceKey());
});

it('marks a file that fails its checks as failed, with the reason, and removes it', function (): void {
    $sent = fakeDirectUploads(['GetObject' => new Result(['Body' => Utils::streamFor('not a model')])]);
    $asset = ProjectAsset::factory()->processing()->create(['original_name' => 'pump.glb']);

    dispatch_sync(new ProcessDirectUpload($asset));

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Failed)
        ->and($asset->error)->toBe('That is not a glTF 2.0 binary (.glb) file.')
        ->and($sent[1]->getName())->toBe('DeleteObject')
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

it('marks the upload failed once its retries run out', function (): void {
    $asset = ProjectAsset::factory()->processing()->create();

    new ProcessDirectUpload($asset)->failed(new RuntimeException('Store down'));

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Failed)
        ->and($asset->error)->toBe('Processing failed. Try uploading it again.');
});

it('leaves the original for a retry when the store fails', function (): void {
    $sent = fakeDirectUploads(['GetObject' => new RuntimeException('Store down')]);
    $asset = ProjectAsset::factory()->processing()->create();

    // One attempt, as a worker would make it before retrying.
    expect(fn () => resolve(ProcessDirectUpload::class, ['asset' => $asset])->handle(resolve(DirectUploads::class), resolve(ProcessProjectAssetFile::class)))
        ->toThrow(RuntimeException::class, 'Store down')
        ->and($asset->refresh()->status)->toBe(ProjectAssetStatus::Processing)
        ->and($sent)->toHaveCount(1);
});

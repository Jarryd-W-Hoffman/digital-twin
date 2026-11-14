<?php

declare(strict_types=1);

use App\Actions\ConvertMesh;
use App\Enums\ProjectAssetStatus;
use App\Exceptions\ConversionFailed;
use App\Models\ProjectAsset;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->asset = ProjectAsset::factory()->mesh()->processing()->create(['details' => null, 'crs' => null, 'options' => []]);
    Storage::disk('local')->put($this->asset->sourceKey(), "PK\x03\x04zipped export");
});

it('tiles a zipped export into the library and drops the original', function (): void {
    fakeMeshConverter();

    $asset = resolve(ConvertMesh::class)->handle($this->asset);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->entry)->toBe('tileset.json')
        ->and($asset->files)->toBe(2)
        ->and($asset->crs)->toBe('EPSG:2193')
        ->and($asset->details)->toEqual(['triangles' => 3000, 'origin' => ['lon' => 174.767, 'lat' => -36.848, 'height' => 33.0, 'source' => 'metadata.xml']])
        ->and($asset->log)->toBe("Downloaded survey.zip\nsurvey.obj: 3,000 triangles, 101.1 × 61.85 × 6 m, in 49 tiles\nGeoreferenced by metadata.xml (EPSG:2193)\n2 files written")
        ->and(Storage::disk('local')->exists($asset->directory.'/tileset.json'))->toBeTrue()
        ->and(Storage::disk('local')->exists($asset->directory.'/LOD-0/Mesh.b3dm'))->toBeTrue()
        ->and(Storage::disk('local')->exists($asset->sourceKey()))->toBeFalse()
        ->and(is_dir(storage_path('app/conversions/'.$asset->id)))->toBeFalse();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
        'meridian-mesh',
        storage_path('app/conversions/'.$asset->id.'/source.zip'),
        storage_path('app/conversions/'.$asset->id.'/tiles'),
        '--max-bytes', (string) (20_480 * 1024 * 1024),
    ]);
});

it('passes on the EPSG code and whether y is up', function (): void {
    fakeMeshConverter();
    $this->asset->update(['original_name' => 'survey.obj', 'options' => ['epsg' => 2193, 'y_up' => true]]);
    Storage::disk('local')->put($this->asset->sourceKey(), "v 0 0 0\n");

    $asset = resolve(ConvertMesh::class)->handle($this->asset);

    Process::assertRan(fn (PendingProcess $process): bool => array_slice((array) $process->command, 1, 1) === [storage_path('app/conversions/'.$asset->id.'/source.obj')]
        && array_slice((array) $process->command, -3) === ['--epsg', '2193', '--y-up']);
});

it('says when an OBJ came without its materials', function (): void {
    fakeMeshConverter(summary: '{"obj": "survey.obj", "triangles": 3000, "size": [100, 60, 6], "tiles": 49, "textured": false, "crs": null, "georeference": null}');

    expect(resolve(ConvertMesh::class)->handle($this->asset)->log)
        ->toContain('survey.obj: 3,000 triangles, 100 × 60 × 6 m, in 49 tiles, untextured: its materials (MTL) were not uploaded with it');
});

it('says when a mesh has no georeference', function (): void {
    fakeMeshConverter(summary: '{"obj": "survey.obj", "triangles": 3000, "size": [100, 60, 6], "crs": null, "georeference": null, "tiles": 49}');

    $asset = resolve(ConvertMesh::class)->handle($this->asset);

    expect($asset->crs)->toBeNull()
        ->and($asset->details)->toBe(['triangles' => 3000, 'origin' => null])
        ->and($asset->log)->toContain("Not georeferenced: it starts at a twin's origin, to be placed");
});

it('refuses a zip that is not one', function (): void {
    fakeMeshConverter();
    Storage::disk('local')->put($this->asset->sourceKey(), 'not a zip');

    expect(fn () => resolve(ConvertMesh::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, 'That is not a zip file.');
});

it('passes on why the converter failed, keeping the original for a retry', function (): void {
    fakeMeshConverter(fail: 'The upload has no OBJ file.');

    expect(fn () => resolve(ConvertMesh::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, "The mesh could not be converted:\nThe upload has no OBJ file.");

    expect(Storage::disk('local')->exists($this->asset->sourceKey()))->toBeTrue()
        ->and(Storage::disk('local')->allFiles($this->asset->directory))->toBe([]);
});

it('refuses a run that wrote no tileset', function (): void {
    fakeMeshConverter(writes: false);

    expect(fn () => resolve(ConvertMesh::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, 'meridian-mesh wrote no tileset.json.');
});

it('copes with a converter that prints no summary', function (): void {
    fakeMeshConverter(summary: 'done');

    $asset = resolve(ConvertMesh::class)->handle($this->asset);

    expect($asset->details)->toBe(['triangles' => 0, 'origin' => null])
        ->and($asset->log)->toContain('mesh: 0 triangles, 0 × 0 × 0 m, in 0 tiles');
});

<?php

declare(strict_types=1);

use App\Actions\ConvertIfc;
use App\Enums\ProjectAssetStatus;
use App\Exceptions\ConversionFailed;
use App\Models\ProjectAsset;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->asset = ProjectAsset::factory()->bim()->processing()->create(['details' => null]);
    Storage::disk('local')->put($this->asset->sourceKey(), "\nISO-10303-21;\nHEADER;");
});

it('converts an IFC model into the library and drops the original', function (): void {
    fakeIfcConverter();

    $asset = resolve(ConvertIfc::class)->handle($this->asset);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->entry)->toBe('model.glb')
        ->and($asset->files)->toBe(2)
        ->and($asset->crs)->toBe('EPSG:2193')
        ->and($asset->details)->toEqual(['elements' => 6, 'georeference' => ['lon' => 174.75, 'lat' => -36.85, 'headingDeg' => -30.0, 'source' => 'IfcMapConversion']])
        ->and($asset->log)->toBe("Downloaded shed.ifc\nIFC4, 6 elements, 72 triangles, 20 × 12 × 5.35 m\nGeoreferenced by IfcMapConversion (EPSG:2193)\n2 files written")
        ->and(Storage::disk('local')->exists($asset->directory.'/model.glb'))->toBeTrue()
        ->and(Storage::disk('local')->exists($asset->directory.'/elements.json'))->toBeTrue()
        ->and(Storage::disk('local')->exists($asset->sourceKey()))->toBeFalse()
        ->and(is_dir(storage_path('app/conversions/'.$asset->id)))->toBeFalse();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
        'meridian-ifc',
        storage_path('app/conversions/'.$asset->id.'/source.ifc'),
        storage_path('app/conversions/'.$asset->id.'/model'),
    ]);
});

it('says when a model is placed by its site rather than a map conversion', function (): void {
    fakeIfcConverter(summary: '{"schema": "IFC2X3", "elements": 1, "triangles": 12, "size": [1, 1, 1], "georeference": {"lon": 174.7, "lat": -36.8, "headingDeg": 15.0, "source": "IfcSite"}, "crs": null}');

    $asset = resolve(ConvertIfc::class)->handle($this->asset);

    expect($asset->crs)->toBeNull()
        ->and($asset->log)->toContain("Georeferenced by IfcSite\n");
});

it('says when a model has no georeference', function (): void {
    fakeIfcConverter(summary: '{"schema": "IFC4", "elements": 6, "triangles": 72, "size": [20, 12, 5], "georeference": null, "crs": null}');

    $asset = resolve(ConvertIfc::class)->handle($this->asset);

    expect($asset->details)->toBe(['elements' => 6, 'georeference' => null])
        ->and($asset->log)->toContain("Not georeferenced: it starts at a twin's origin, to be placed");
});

it('refuses files that are not IFC', function (): void {
    fakeIfcConverter();
    Storage::disk('local')->put($this->asset->sourceKey(), 'glTF binary');

    expect(fn () => resolve(ConvertIfc::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, 'That is not an IFC file (STEP, starting ISO-10303-21).');
});

it('passes on why the converter failed, keeping the original for a retry', function (): void {
    fakeIfcConverter(fail: 'The IFC file has no elements with geometry.');

    expect(fn () => resolve(ConvertIfc::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, "The IFC model could not be converted:\nThe IFC file has no elements with geometry.");

    expect(Storage::disk('local')->exists($this->asset->sourceKey()))->toBeTrue()
        ->and(Storage::disk('local')->allFiles($this->asset->directory))->toBe([]);
});

it('refuses a run that wrote no model', function (): void {
    fakeIfcConverter(writes: false);

    expect(fn () => resolve(ConvertIfc::class)->handle($this->asset))
        ->toThrow(ConversionFailed::class, 'meridian-ifc wrote no model.glb.');
});

it('copes with a converter that prints no summary', function (): void {
    fakeIfcConverter(summary: 'done');

    $asset = resolve(ConvertIfc::class)->handle($this->asset);

    expect($asset->details)->toBe(['elements' => 0, 'georeference' => null])
        ->and($asset->log)->toContain('IFC, 0 elements, 0 triangles, 0 × 0 × 0 m');
});

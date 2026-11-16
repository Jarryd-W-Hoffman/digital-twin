<?php

declare(strict_types=1);

use App\Actions\SplitOpenDroneMapExport;
use App\Enums\AuditAction;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Exceptions\ConversionFailed;
use App\Jobs\ConvertUpload;
use App\Models\AuditEvent;
use App\Models\ProjectAsset;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    $this->work = storage_path('framework/testing/odm-'.uniqid());
    File::ensureDirectoryExists($this->work);
    $this->zip = $this->work.'/source.zip';
    $this->export = ProjectAsset::factory()->mesh()->processing()->create([
        'name' => 'Aukerman Park',
        'original_name' => 'all.zip',
        'options' => ['epsg' => 32617, 'opendronemap' => true],
        'captured_on' => '2016-06-29',
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->work);
});

/**
 * The names of the files in a zip.
 *
 * @return list<string>
 */
function zipNames(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path);

    $names = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = (string) $zip->getNameIndex($index);
    }

    $zip->close();
    sort($names);

    return $names;
}

it('adds the orthophoto, DEMs and point cloud to the library, and keeps only the 3D model', function (string $prefix): void {
    File::put($this->zip, openDroneMapExport(prefix: $prefix));
    Storage::disk('local')->put($this->export->sourceKey(), File::get($this->zip));

    $added = resolve(SplitOpenDroneMapExport::class)->handle($this->export, $this->zip, $this->work);

    expect(collect($added)->map(fn (ProjectAsset $asset): array => [$asset->name, $asset->kind, $asset->original_name, $asset->bytes]))->toEqual(collect([
        ['Aukerman Park: orthophoto', ProjectAssetKind::Imagery, 'odm_orthophoto.tif', 5],
        ['Aukerman Park: surface model (DSM)', ProjectAssetKind::Terrain, 'dsm.tif', 7],
        ['Aukerman Park: bare ground (DTM)', ProjectAssetKind::Terrain, 'dtm.tif', 6],
        // The compressed point cloud, not both.
        ['Aukerman Park: point cloud', ProjectAssetKind::PointCloud, 'odm_georeferenced_model.laz', 6],
    ]));

    foreach ($added as $asset) {
        expect($asset->project_id)->toBe($this->export->project_id)
            ->and($asset->status)->toBe(ProjectAssetStatus::Processing)
            ->and($asset->options)->toBe([])
            ->and($asset->captured_on?->toDateString())->toBe('2016-06-29')
            ->and($asset->directory)->toBe($this->export->project->storagePrefix().'/assets/'.$asset->id)
            ->and(Storage::disk('local')->exists($asset->sourceKey()))->toBeTrue();
        Queue::assertPushed(ConvertUpload::class, fn (ConvertUpload $job): bool => $job->asset->is($asset));
    }

    $expected = [
        'opendronemap/odm_georeferencing/coords.txt',
        'opendronemap/odm_texturing/odm_textured_model_geo.mtl',
        'opendronemap/odm_texturing/odm_textured_model_geo.obj',
        'opendronemap/odm_texturing/odm_textured_model_geo_material0000_map_Kd.png',
    ];
    $stored = $this->work.'/stored.zip';
    File::put($stored, (string) Storage::disk('local')->get($this->export->sourceKey()));

    expect(Storage::disk('local')->get($added[0]->sourceKey()))->toBe('ortho')
        ->and(Storage::disk('local')->get($added[3]->sourceKey()))->toBe('points')
        ->and(zipNames($this->zip))->toBe($expected)
        ->and(zipNames($stored))->toBe($expected)
        ->and(is_dir($this->work.'/opendronemap'))->toBeFalse()
        // Split once: a retry converts the mesh.
        ->and($this->export->refresh()->options)->toBe(['epsg' => 32617])
        ->and(AuditEvent::query()->where('action', AuditAction::UploadAdded)->pluck('details')->all())
        ->toContain(['kind' => 'imagery', 'file' => 'odm_orthophoto.tif', 'from' => 'all.zip']);
    Queue::assertPushed(ConvertUpload::class, 4);
})->with([
    "WebODM's all.zip" => [''],
    'a zipped project folder' => ['aukerman/'],
]);

it('takes an uncompressed point cloud when there is no other, and adds nothing when there is only the model', function (): void {
    File::put($this->zip, openDroneMapExport([
        'odm_georeferencing/odm_georeferenced_model.las' => 'points',
        'odm_texturing/odm_textured_model_geo.obj' => 'v 0 0 0',
    ]));

    $added = resolve(SplitOpenDroneMapExport::class)->handle($this->export, $this->zip, $this->work);

    expect($added)->toHaveCount(1)
        ->and($added[0]->original_name)->toBe('odm_georeferenced_model.las');

    File::put($this->zip, openDroneMapExport(['odm_texturing/odm_textured_model_geo.obj' => 'v 0 0 0']));

    expect(resolve(SplitOpenDroneMapExport::class)->handle($this->export, $this->zip, $this->work))->toBe([]);
});

it('adds the other outputs of an export without a 3D model, then says so', function (): void {
    File::put($this->zip, openDroneMapExport(['odm_orthophoto/odm_orthophoto.tif' => 'ortho', 'odm_georeferencing/coords.txt' => 'WGS84 UTM 17N']));
    Storage::disk('local')->put($this->export->sourceKey(), 'as uploaded');

    expect(fn () => resolve(SplitOpenDroneMapExport::class)->handle($this->export, $this->zip, $this->work))
        ->toThrow(ConversionFailed::class, 'The export has no textured 3D model (odm_texturing). Its other outputs were added to the library.');

    expect($this->export->project->assets()->where('kind', ProjectAssetKind::Imagery)->count())->toBe(1)
        ->and(Storage::disk('local')->get($this->export->sourceKey()))->toBe('as uploaded')
        ->and($this->export->refresh()->options)->toBe(['epsg' => 32617]);
});

it('says when an export has only its georeferencing', function (): void {
    File::put($this->zip, openDroneMapExport(['odm_georeferencing/coords.txt' => 'WGS84 UTM 17N']));

    expect(fn () => resolve(SplitOpenDroneMapExport::class)->handle($this->export, $this->zip, $this->work))
        ->toThrow(ConversionFailed::class, 'The export has no textured 3D model (odm_texturing).');
});

it('refuses what is not an OpenDroneMap export', function (string $bytes, string $message): void {
    File::put($this->zip, $bytes);

    expect(fn () => resolve(SplitOpenDroneMapExport::class)->handle($this->export, $this->zip, $this->work))
        ->toThrow(ConversionFailed::class, $message);

    expect($this->export->project->assets()->count())->toBe(1);
})->with([
    'another zip' => [fn (): string => openDroneMapExport(['survey.obj' => 'v 0 0 0']), "That isn't an OpenDroneMap export: it has none of odm_orthophoto, odm_dem, odm_georeferencing or odm_texturing."],
    'not a zip' => ['not a zip', "The zip file can't be read."],
    'a file outside its folder' => [fn (): string => openDroneMapExport(['odm_texturing/../../evil.obj' => 'v 0 0 0']), 'The zip has a file outside its folder: odm_texturing/../../evil.obj'],
]);

it('refuses an export that unpacks to more than the limit', function (): void {
    config(['meridian.mesh.max_extracted_mb' => 0]);
    File::put($this->zip, openDroneMapExport());

    expect(fn () => resolve(SplitOpenDroneMapExport::class)->handle($this->export, $this->zip, $this->work))
        ->toThrow(ConversionFailed::class, 'The export unpacks to more than 0 MB.');
});

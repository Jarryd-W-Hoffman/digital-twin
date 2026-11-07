<?php

declare(strict_types=1);

use App\Actions\ConvertPointCloud;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Exceptions\PointCloudConversionFailed;
use App\Models\ProjectAsset;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->asset = ProjectAsset::factory()->processing()->create([
        'kind' => ProjectAssetKind::PointCloud,
        'original_name' => 'Quay Scan.LAZ',
        'options' => [],
    ]);
    Storage::disk('local')->put($this->asset->sourceKey(), 'LASF1.4 points');
});

it('tiles a georeferenced cloud into the library and drops the original', function (): void {
    fakeConverter(epsg: 2193, points: 1_234_567);

    $asset = resolve(ConvertPointCloud::class)->handle($this->asset);

    expect($asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($asset->entry)->toBe('tileset.json')
        ->and($asset->files)->toBe(2)
        ->and($asset->bytes)->toBe(mb_strlen(tilesetJson(), '8bit') + 4)
        ->and($asset->crs)->toBe('EPSG:2193')
        ->and($asset->progress)->toBe(100)
        ->and($asset->log)->toBe("Downloaded Quay Scan.LAZ\n1,234,567 points, EPSG:2193\nPrepared for tiling\nTiled\n2 files written")
        ->and(Storage::disk('local')->exists($asset->directory.'/tileset.json'))->toBeTrue()
        ->and(Storage::disk('local')->exists($asset->directory.'/r/0.pnts'))->toBeTrue()
        ->and(Storage::disk('local')->exists($asset->sourceKey()))->toBeFalse()
        ->and(is_dir(storage_path('app/conversions/'.$asset->id)))->toBeFalse();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'py3dtiles'
        && array_slice($process->command, -2) === ['--srs_out', '4978']
        && in_array('--pyproj-always-xy', $process->command, true)
        && $process->timeout === 240 * 60);
});

it('keeps a cloud without a coordinate system in local metres', function (): void {
    fakeConverter(epsg: null);

    $asset = resolve(ConvertPointCloud::class)->handle($this->asset);

    expect($asset->crs)->toBeNull()
        ->and($asset->log)->toContain('no coordinate system (placed as local metres)');
    Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'py3dtiles' && ! in_array('--srs_out', $process->command, true));
});

it('assigns the given EPSG code and thins to the given spacing', function (): void {
    fakeConverter(epsg: null);
    $this->asset->update(['options' => ['epsg' => 2193, 'spacing' => 0.05]]);
    $pipeline = null;
    Process::fake(["'pdal' 'pipeline'*" => function (PendingProcess $process) use (&$pipeline): string {
        $pipeline = json_decode((string) file_get_contents($process->command[2]), true);
        file_put_contents((string) end($pipeline)['filename'], 'LASF');

        return '';
    }]);

    $asset = resolve(ConvertPointCloud::class)->handle($this->asset);

    expect($asset->crs)->toBe('EPSG:2193')
        ->and($pipeline[0]['override_srs'])->toBe('EPSG:2193')
        ->and($pipeline[1])->toBe(['type' => 'filters.voxelcenternearestneighbor', 'cell' => 0.05])
        ->and($pipeline[2]['type'])->toBe('writers.las');
});

it('names a CRS PDAL describes without an authority code', function (array $srs, string $crs): void {
    fakeConverter();
    Process::fake(["'pdal' 'info'*" => json_encode(['summary' => ['num_points' => 5, 'srs' => ['json' => $srs]]])]);

    expect(resolve(ConvertPointCloud::class)->handle($this->asset)->crs)->toBe($crs);
})->with([
    [['name' => 'Site grid'], 'Site grid'],
    [[], 'Custom'],
]);

it('refuses files that are not point clouds', function (): void {
    fakeConverter();
    Storage::disk('local')->put($this->asset->sourceKey(), 'PK zip');

    resolve(ConvertPointCloud::class)->handle($this->asset);
})->throws(PointCloudConversionFailed::class, 'That is not a LAS, LAZ or E57 point cloud.');

it('accepts E57 scans', function (): void {
    fakeConverter();
    $this->asset->update(['original_name' => 'room.e57']);
    Storage::disk('local')->put($this->asset->sourceKey(), 'ASTM-E57 ...');

    expect(resolve(ConvertPointCloud::class)->handle($this->asset)->status)->toBe(ProjectAssetStatus::Ready);
});

it('explains a missing original', function (): void {
    fakeConverter();
    Storage::disk('local')->delete($this->asset->sourceKey());

    resolve(ConvertPointCloud::class)->handle($this->asset);
})->throws(PointCloudConversionFailed::class, 'The uploaded file is missing.');

it('refuses an empty cloud', function (): void {
    fakeConverter(points: 0);

    resolve(ConvertPointCloud::class)->handle($this->asset);
})->throws(PointCloudConversionFailed::class, 'The file has no points.');

it('passes on the tail of a failing tool, keeping the original for a retry', function (string $step, string $message): void {
    fakeConverter(fail: [$step => "line 1\nline 2\nline 3\nline 4\nline 5\nline 6\nboom"]);
    Storage::disk('local')->put($this->asset->directory.'/stale.pnts', 'x');

    expect(fn () => resolve(ConvertPointCloud::class)->handle($this->asset))
        ->toThrow(PointCloudConversionFailed::class, $message.":\nline 3\nline 4\nline 5\nline 6\nboom");

    expect(Storage::disk('local')->exists($this->asset->sourceKey()))->toBeTrue()
        ->and(Storage::disk('local')->allFiles($this->asset->directory))->toBe([])
        ->and(is_dir(storage_path('app/conversions/'.$this->asset->id)))->toBeFalse();
})->with([
    ['info', 'PDAL could not read the file'],
    ['pipeline', 'PDAL could not prepare the points'],
    ['py3dtiles', 'py3dtiles could not tile the points'],
]);

it('says so when a tool fails silently', function (): void {
    fakeConverter();
    Process::fake(["'pdal' 'info'*" => Process::result(exitCode: 1)]);

    resolve(ConvertPointCloud::class)->handle($this->asset);
})->throws(PointCloudConversionFailed::class, 'PDAL could not read the file.');

it('refuses a run that wrote no tileset', function (): void {
    fakeConverter();
    Process::fake(["'py3dtiles'*" => 'done']);

    resolve(ConvertPointCloud::class)->handle($this->asset);
})->throws(PointCloudConversionFailed::class, 'py3dtiles wrote no tileset.json.');

it('starts each run with a clean log and no error', function (): void {
    fakeConverter();
    $this->asset->update(['log' => 'old run', 'error' => 'old failure', 'progress' => 60]);
    Process::fake(["'pdal' 'info'*" => fn (): never => throw new RuntimeException('stop')]);

    expect(fn () => resolve(ConvertPointCloud::class)->handle($this->asset))->toThrow(RuntimeException::class, 'stop');
    expect($this->asset->refresh()->log)->toBe('Downloaded Quay Scan.LAZ')
        ->and($this->asset->error)->toBeNull()
        ->and($this->asset->progress)->toBe(5);
});

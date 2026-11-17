<?php

declare(strict_types=1);

use App\Actions\ConvertIfc;
use App\Actions\ConvertImagery;
use App\Actions\ConvertMesh;
use App\Actions\ConvertPointCloud;
use App\Actions\ConvertTerrain;
use App\Actions\ConvertVector;
use App\Actions\SendPhotosToNodeOdm;
use App\Enums\Feature;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Jobs\ConvertUpload;
use App\Jobs\WatchPhotoProcessing;
use App\Models\ProjectAsset;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->asset = ProjectAsset::factory()->processing()->create(['kind' => ProjectAssetKind::PointCloud, 'original_name' => 'scan.las']);
});

it('runs on the conversions queue, once, for up to four hours', function (): void {
    $job = new ConvertUpload($this->asset);

    expect($job->connection)->toBe('conversions')
        ->and($job->queue)->toBe('conversions')
        ->and(new ReflectionClass($job)->getAttributes(Tries::class)[0]->getArguments())->toBe([1])
        ->and(new ReflectionClass($job)->getAttributes(Timeout::class)[0]->getArguments())->toBe([14400]);
});

it('gives conversions a connection that outlasts them, on the default driver', function (string $default, string $driver): void {
    $set = function (string $value): void {
        putenv('QUEUE_CONNECTION='.$value);
        $_ENV['QUEUE_CONNECTION'] = $_SERVER['QUEUE_CONNECTION'] = $value;
    };
    $set($default);

    try {
        $connection = (require config_path('queue.php'))['connections']['conversions'];
    } finally {
        $set('sync');
    }

    expect($connection['driver'])->toBe($driver)
        ->and($connection['queue'])->toBe('conversions')
        ->and($connection['retry_after'])->toBeGreaterThan(14400);
})->with([['database', 'database'], ['redis', 'redis']]);

it('converts the cloud', function (): void {
    fakeConverter();
    Storage::disk('local')->put($this->asset->sourceKey(), 'LASF');

    dispatch_sync(new ConvertUpload($this->asset));

    expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Ready);
});

it('shows why a conversion failed on the library item', function (): void {
    fakeConverter();
    Storage::disk('local')->put($this->asset->sourceKey(), 'nope');

    dispatch_sync(new ConvertUpload($this->asset));

    expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Failed)
        ->and($this->asset->error)->toBe('That is not a LAS, LAZ or E57 point cloud.');
});

it('marks the item failed when the worker gives up', function (): void {
    new ConvertUpload($this->asset)->failed(new RuntimeException('Killed'));

    expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Failed)
        ->and($this->asset->error)->toBe('The conversion stopped unexpectedly. Retry it, or upload the file again.');
});

it('converts a DEM to terrain', function (): void {
    fakeTerrainTiler();
    $this->asset->update(['kind' => ProjectAssetKind::Terrain, 'original_name' => 'dem.tif']);
    Storage::disk('local')->put($this->asset->sourceKey(), "II*\0");

    dispatch_sync(new ConvertUpload($this->asset));

    expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($this->asset->entry)->toBe('layer.json');
});

it('converts an IFC model to a GLB', function (): void {
    fakeIfcConverter();
    $this->asset->update(['kind' => ProjectAssetKind::Bim, 'original_name' => 'shed.ifc']);
    Storage::disk('local')->put($this->asset->sourceKey(), "ISO-10303-21;\nHEADER;");

    dispatch_sync(new ConvertUpload($this->asset));

    expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($this->asset->entry)->toBe('model.glb');
});

it('converts a map layer to GeoJSON', function (): void {
    fakeVectorConverter();
    $this->asset->update(['kind' => ProjectAssetKind::Vector, 'original_name' => 'parcels.zip']);
    Storage::disk('local')->put($this->asset->sourceKey(), 'PK');

    dispatch_sync(new ConvertUpload($this->asset));

    expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($this->asset->entry)->toBe('layer.geojson');
});

it('tiles a mesh', function (): void {
    fakeMeshConverter();
    $this->asset->update(['kind' => ProjectAssetKind::Mesh, 'original_name' => 'survey.obj']);
    Storage::disk('local')->put($this->asset->sourceKey(), "v 0 0 0\n");

    dispatch_sync(new ConvertUpload($this->asset));

    expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($this->asset->entry)->toBe('tileset.json');
});

it('sends drone photos to be processed', function (): void {
    Queue::fake();
    config(['meridian.photos.nodeodm_url' => 'http://nodeodm:3000']);
    Http::fake([
        'nodeodm:3000/task/new/init' => Http::response(['uuid' => 'task-1']),
        'nodeodm:3000/task/new/*' => Http::response(['success' => true]),
    ]);
    $this->asset->update(['kind' => ProjectAssetKind::Mesh, 'original_name' => 'photos.zip', 'options' => ['photos' => true]]);
    $this->asset->project->organisation->update(['features' => [Feature::PhotoProcessing]]);
    Storage::disk('local')->put($this->asset->sourceKey(), dronePhotos(5));

    new ConvertUpload($this->asset)->handle(...array_map(resolve(...), [ConvertPointCloud::class, ConvertTerrain::class, ConvertIfc::class, ConvertVector::class, ConvertImagery::class, ConvertMesh::class, SendPhotosToNodeOdm::class]));

    expect($this->asset->refresh()->options)->toBe(['photos' => true, 'nodeodm_task' => 'task-1']);
    Queue::assertPushed(WatchPhotoProcessing::class);
});

it('tiles an image', function (): void {
    fakeImageryTiler();
    $this->asset->update(['kind' => ProjectAssetKind::Imagery, 'original_name' => 'ortho.tif']);
    Storage::disk('local')->put($this->asset->sourceKey(), "II*\0");

    dispatch_sync(new ConvertUpload($this->asset));

    expect($this->asset->refresh()->status)->toBe(ProjectAssetStatus::Ready)
        ->and($this->asset->entry)->toBe('imagery.json');
});

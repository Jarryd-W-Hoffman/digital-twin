<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\ProjectAssetKind;
use App\Enums\ProjectAssetStatus;
use App\Exceptions\ConversionFailed;
use App\Jobs\ConvertUpload;
use App\Models\ProjectAsset;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

final readonly class SplitOpenDroneMapExport
{
    /**
     * OpenDroneMap's outputs that become library items of their own: where
     * each is in its project, what it is, and what it's called.
     *
     * @var array<string, array{0: ProjectAssetKind, 1: string}>
     */
    private const array OUTPUTS = [
        'odm_orthophoto/odm_orthophoto.tif' => [ProjectAssetKind::Imagery, 'orthophoto'],
        'odm_dem/dsm.tif' => [ProjectAssetKind::Terrain, 'surface model (DSM)'],
        'odm_dem/dtm.tif' => [ProjectAssetKind::Terrain, 'bare ground (DTM)'],
        'odm_georeferencing/odm_georeferenced_model.laz' => [ProjectAssetKind::PointCloud, 'point cloud'],
        'odm_georeferencing/odm_georeferenced_model.las' => [ProjectAssetKind::PointCloud, 'point cloud'],
    ];

    /**
     * What the textured 3D model needs: its folder, and where it is.
     */
    private const array MESH = ['odm_texturing/', 'odm_georeferencing/coords.txt', 'odm_georeferencing/proj.txt'];

    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Splits a zipped OpenDroneMap project (WebODM's "all.zip", or the project
     * folder zipped) into library items: its orthophoto, surface and
     * bare-ground models and point cloud each become an upload of their own,
     * dated as the export is and queued for conversion. The export itself
     * becomes its textured 3D model: the zip is rewritten, here and in
     * storage, to hold only that and its georeferencing, so a retry converts
     * the mesh and adds nothing twice.
     *
     * @return list<ProjectAsset> The items added.
     */
    public function handle(ProjectAsset $export, string $zip, string $work): array
    {
        $archive = new ZipArchive;
        throw_unless($archive->open($zip, ZipArchive::RDONLY) === true, ConversionFailed::class, __("The zip file can't be read."));

        $disk = Storage::disk(config()->string('meridian.assets_disk'));
        $limit = config()->integer('meridian.mesh.max_extracted_mb') * 1024 * 1024;
        $entries = $this->entries($archive);
        $outputs = [];
        $mesh = [];
        $bytes = 0;

        foreach ($entries as $name => $size) {
            $path = $this->withinProject($name);
            $bytes += $size;

            if (isset(self::OUTPUTS[$path])) {
                $outputs[$path] ??= $name;
            } elseif (Str::startsWith($path, self::MESH) && ! str_ends_with($path, '/')) {
                $mesh[$path] = $name;
            }
        }

        throw_if($bytes > $limit, ConversionFailed::class, __('The export unpacks to more than :mb MB.', ['mb' => number_format($limit / 1024 / 1024)]));
        throw_if($outputs === [] && $mesh === [], ConversionFailed::class, __("That isn't an OpenDroneMap export: it has none of odm_orthophoto, odm_dem, odm_georeferencing or odm_texturing."));

        // One point cloud: the compressed one if there are both.
        if (isset($outputs['odm_georeferencing/odm_georeferenced_model.laz'])) {
            unset($outputs['odm_georeferencing/odm_georeferenced_model.las']);
        }

        $added = [];

        foreach ($outputs as $path => $name) {
            $id = (string) Str::uuid7();
            $disk->writeStream('uploads/'.$id.'/'.basename($path), $this->stream($archive, $name));
            $added[] = ['id' => $id, 'path' => $path, 'bytes' => $entries[$name]];
        }

        $hasMesh = Arr::first(array_keys($mesh), fn (string $path): bool => str_ends_with($path, '.obj')) !== null;

        if ($hasMesh) {
            $this->keepOnly($archive, $mesh, $work, $zip);
        }

        $archive->close();

        $assets = DB::transaction(fn (): array => array_map(fn (array $output): ProjectAsset => $this->add($export, ...$output), $added));

        if ($hasMesh) {
            $slim = fopen($zip, 'rb');
            assert($slim !== false);
            $disk->writeStream($export->sourceKey(), $slim);
        }

        $export->update(['options' => Arr::except($export->options ?? [], ['opendronemap'])]);

        throw_unless($hasMesh, ConversionFailed::class, $assets === []
            ? __('The export has no textured 3D model (odm_texturing).')
            : __('The export has no textured 3D model (odm_texturing). Its other outputs were added to the library.'));

        return $assets;
    }

    /**
     * Every file's name in the zip, and how big it unpacks to.
     *
     * @return array<string, int>
     */
    private function entries(ZipArchive $archive): array
    {
        $entries = [];

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);
            throw_if($stat === false, ConversionFailed::class, __("The zip file can't be read."));
            $entries[$stat['name']] = (int) $stat['size'];
        }

        return $entries;
    }

    /**
     * A file's path from its project folder: WebODM's zip has the outputs at
     * the top, a zipped project folder one level down.
     */
    private function withinProject(string $name): string
    {
        foreach ([...array_keys(self::OUTPUTS), ...self::MESH] as $output) {
            $folder = Str::before($output, '/').'/';
            $at = mb_strpos('/'.$name, '/'.$folder);

            if ($at !== false) {
                return mb_substr($name, $at);
            }
        }

        return $name;
    }

    /**
     * @return resource
     */
    private function stream(ZipArchive $archive, string $name): mixed
    {
        $stream = $archive->getStream($name);
        throw_if($stream === false, ConversionFailed::class, __("The zip file can't be read."));

        return $stream;
    }

    /**
     * Rewrites the zip with only the 3D model's files, at their places in the
     * project.
     *
     * @param  array<string, string>  $mesh  Each file's path in the project, and its name in the zip.
     */
    private function keepOnly(ZipArchive $archive, array $mesh, string $work, string $zip): void
    {
        $folder = $work.'/opendronemap';
        File::deleteDirectory($folder);

        foreach ($mesh as $path => $name) {
            throw_if(str_contains($path, '..'), ConversionFailed::class, __('The zip has a file outside its folder: :name', ['name' => $name]));
            File::ensureDirectoryExists(dirname($folder.'/'.$path));
            $out = fopen($folder.'/'.$path, 'wb');
            assert($out !== false);
            stream_copy_to_stream($this->stream($archive, $name), $out);
            fclose($out);
        }

        $slim = new ZipArchive;
        $slim->open($work.'/opendronemap.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach (array_keys($mesh) as $path) {
            $slim->addFile($folder.'/'.$path, 'opendronemap/'.$path);
        }

        $slim->close();
        File::deleteDirectory($folder);
        File::move($work.'/opendronemap.zip', $zip);
    }

    /**
     * Adds one output to the library beside the export, and queues it.
     */
    private function add(ProjectAsset $export, string $id, string $path, int $bytes): ProjectAsset
    {
        [$kind, $what] = self::OUTPUTS[$path];
        $name = Str::limit($export->name.': '.$what, 120, '');
        $asset = $export->project->assets()->create([
            'id' => $id,
            'kind' => $kind,
            'name' => $name,
            'original_name' => basename($path),
            'directory' => $export->project->storagePrefix().'/assets/'.$id,
            'entry' => '',
            'bytes' => $bytes,
            'files' => 0,
            'status' => ProjectAssetStatus::Processing,
            'options' => [],
            'captured_on' => $export->captured_on,
        ]);
        $this->audit->handle(AuditAction::UploadAdded, $export->project, $name, ['kind' => $kind->value, 'file' => $asset->original_name, 'from' => $export->original_name]);
        dispatch(new ConvertUpload($asset))->afterCommit();

        return $asset;
    }
}

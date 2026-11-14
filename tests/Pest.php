<?php

declare(strict_types=1);

use App\Actions\IssueGatewayToken;
use App\Actions\SaveTwinVersion;
use App\Enums\OrganisationRole;
use App\Models\Organisation;
use App\Models\Twin;
use App\Models\User;
use App\Support\DirectUploads;
use App\Support\SampleTwins;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Browser', 'Feature', 'Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

/**
 * A user who belongs to a fresh organisation with the given role.
 *
 * @return array{User, Organisation}
 */
function member(OrganisationRole $role = OrganisationRole::Owner): array
{
    $organisation = Organisation::factory()->create();
    $user = User::factory()->create(['current_organisation_id' => $organisation->id]);
    $organisation->memberships()->create(['user_id' => $user->id, 'role' => $role]);

    return [$user, $organisation];
}

function twinIn(Organisation $organisation): Twin
{
    $twin = Twin::factory()->for($organisation)->create();
    resolve(SaveTwinVersion::class)->handle($twin, null, resolve(SampleTwins::class)->definition('harbour-depot'));

    return $twin;
}

/**
 * The smallest file that passes the GLB header check.
 */
function glbUpload(string $name = 'pump.glb', ?string $bytes = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'glb');
    file_put_contents($path, $bytes ?? 'glTF'.pack('VV', 2, 12));

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * @param  array<string, string>  $entries  Name to contents.
 * @param  list<string>  $symlinks  Entry names to mark as symbolic links.
 */
function tilesetZip(array $entries, array $symlinks = [], string $name = 'scan.zip'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);

    foreach ($entries as $entry => $contents) {
        str_ends_with($entry, '/') ? $zip->addEmptyDir($entry) : $zip->addFromString($entry, $contents);
    }

    foreach ($symlinks as $entry) {
        $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, 0120777 << 16);
    }

    $zip->close();

    return new UploadedFile($path, $name, null, null, true);
}

function tilesetJson(): string
{
    return (string) json_encode(['asset' => ['version' => '1.1'], 'geometricError' => 100, 'root' => ['geometricError' => 0, 'content' => ['uri' => 'tiles/0.glb']]]);
}

/**
 * Swaps the bucket behind direct uploads for the SDK's mock handler. Each
 * command the app sends is recorded, and answered from $results by name
 * (a Result, an exception, or a callable taking the command). The clients are
 * built once, since each S3 client is heavy.
 *
 * @param  array<string, mixed>  $results  Command name to answer.
 * @return ArrayObject<int, CommandInterface>
 */
function fakeDirectUploads(array $results = [], int $partBytes = 5 * 1024 * 1024): ArrayObject
{
    /** @var ArrayObject<string, mixed>|null $bucket */
    static $bucket = null;
    /** @var array{0: S3Client, 1: S3Client}|null $clients */
    static $clients = null;

    config(['meridian.direct_uploads.enabled' => true, 'meridian.assets_disk' => 's3', 'filesystems.disks.s3.driver' => 's3']);

    $bucket ??= new ArrayObject();
    /** @var ArrayObject<int, CommandInterface> $sent */
    $sent = new ArrayObject();
    $bucket['results'] = $results;
    $bucket['sent'] = $sent;

    $clients ??= array_map(fn (string $endpoint): S3Client => new S3Client([
        'version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $endpoint, 'use_path_style_endpoint' => true,
        'credentials' => ['key' => 'k', 'secret' => 's'],
        'handler' => function (CommandInterface $command) use ($bucket): PromiseInterface {
            /** @var array<string, mixed> $answers */
            $answers = $bucket['results'];
            /** @var ArrayObject<int, CommandInterface> $log */
            $log = $bucket['sent'];
            $log[] = $command;
            $answer = $answers[$command->getName()] ?? new Result([]);
            $answer = is_callable($answer) ? $answer($command) : $answer;

            return $answer instanceof Throwable
                ? Create::rejectionFor($answer)
                : Create::promiseFor($answer);
        },
    ]), ['http://rustfs:9000', 'http://localhost:9014']);

    app()->instance(DirectUploads::class, new DirectUploads($clients[0], $clients[1], 'meridian', $partBytes));

    return $sent;
}

/**
 * Stands in for PDAL and py3dtiles. `pdal info` reports $points points in
 * $epsg (null: no coordinate system), `pdal pipeline` writes the LAS its
 * pipeline names (and the height grid, for a georeferenced cloud), and `py3dtiles convert` writes a two-file tileset to --out.
 * A command named in $fail exits 1 with that text on stderr.
 *
 * @param  array<string, string>  $fail  'info', 'pipeline' or 'py3dtiles' to its error.
 */
function fakeConverter(?int $epsg = 2193, int $points = 1200, array $fail = []): void
{
    $failed = fn (string $step): ?ProcessResult => isset($fail[$step])
        ? Process::result(errorOutput: $fail[$step], exitCode: 1)
        : null;

    Process::fake([
        "'pdal' 'info'*" => fn (): ProcessResult|string => $failed('info') ?? (string) json_encode(['summary' => [
            'num_points' => $points,
            ...($epsg === null ? [] : ['srs' => ['json' => ['name' => 'Test CRS', 'id' => ['authority' => 'EPSG', 'code' => $epsg]]]]),
        ]]),
        "'pdal' 'pipeline'*" => function (PendingProcess $process) use ($failed): ProcessResult|string {
            /** @var list<string> $command */
            $command = $process->command;
            /** @var list<array<string, mixed>> $stages */
            $stages = json_decode((string) file_get_contents($command[2]), true);

            foreach ($stages as $stage) {
                if (str_starts_with((string) ($stage['type'] ?? ''), 'writers.')) {
                    file_put_contents((string) $stage['filename'], $stage['type'] === 'writers.gdal' ? "II*\0 heights" : 'LASF prepared');
                }
            }

            return $failed('pipeline') ?? '';
        },
        "'py3dtiles'*" => function (PendingProcess $process) use ($failed): ProcessResult|string {
            if (($result = $failed('py3dtiles')) instanceof ProcessResult) {
                return $result;
            }

            /** @var list<string> $command */
            $command = $process->command;
            $out = $command[(int) array_search('--out', $command, true) + 1];
            File::ensureDirectoryExists($out.'/r');
            file_put_contents($out.'/tileset.json', tilesetJson());
            file_put_contents($out.'/r/0.pnts', 'pnts');

            return 'Tiler done';
        },
    ]);
}

/**
 * Fakes meridian-terrain: it writes a layer.json, one tile and the surface asked for, then prints
 * its summary, unless $fail gives it error output to fail with.
 */
function fakeTerrainTiler(?string $fail = null, bool $writes = true, string $summary = '{"crs": "EPSG:2193", "tiles": 120, "maxZoom": 16, "bounds": [174.74, -36.86, 174.77, -36.84], "heights": [20.04, 100]}'): void
{
    Process::fake([
        "'meridian-terrain'*" => function (PendingProcess $process) use ($fail, $writes, $summary): ProcessResult|string {
            if ($fail !== null) {
                return Process::result(errorOutput: $fail, exitCode: 2);
            }

            if ($writes) {
                /** @var list<string> $command */
                $command = $process->command;
                File::ensureDirectoryExists($command[2].'/0/1');
                file_put_contents($command[2].'/layer.json', '{"format": "quantized-mesh-1.0"}');
                file_put_contents($command[2].'/0/1/0.terrain', 'mesh');

                if (in_array('--surface', $command, true)) {
                    file_put_contents($command[(int) array_search('--surface', $command, true) + 1], "II*\0 heights");
                }
            }

            return "progress\n".$summary;
        },
    ]);
}

/**
 * Stands in for meridian-ifc: writes a GLB and an element list, and prints its summary.
 */
function fakeIfcConverter(?string $fail = null, bool $writes = true, string $summary = '{"schema": "IFC4", "elements": 6, "triangles": 72, "size": [20.0, 12.0, 5.35], "shift": [0.0, 0.0], "georeference": {"lon": 174.75, "lat": -36.85, "headingDeg": -30.0, "source": "IfcMapConversion"}, "crs": "EPSG:2193"}'): void
{
    Process::fake([
        "'meridian-ifc'*" => function (PendingProcess $process) use ($fail, $writes, $summary): ProcessResult|string {
            if ($fail !== null) {
                return Process::result(errorOutput: $fail, exitCode: 1);
            }

            if ($writes) {
                /** @var list<string> $command */
                $command = $process->command;
                File::ensureDirectoryExists($command[2]);
                file_put_contents($command[2].'/model.glb', 'glTF');
                file_put_contents($command[2].'/elements.json', '[]');
            }

            return $summary;
        },
    ]);
}

/**
 * Stands in for meridian-mesh: writes a tileset with a tile, and prints its summary.
 */
function fakeMeshConverter(?string $fail = null, bool $writes = true, string $summary = '{"obj": "survey.obj", "vertices": 1581, "triangles": 3000, "size": [101.1, 61.85, 6.0], "crs": "EPSG:2193", "georeference": {"lon": 174.767, "lat": -36.848, "height": 33.0, "source": "metadata.xml"}, "tiles": 49}'): void
{
    Process::fake([
        "'meridian-mesh'*" => function (PendingProcess $process) use ($fail, $writes, $summary): ProcessResult|string {
            if ($fail !== null) {
                return Process::result(errorOutput: $fail, exitCode: 1);
            }

            if ($writes) {
                /** @var list<string> $command */
                $command = $process->command;
                File::ensureDirectoryExists($command[2].'/LOD-0');
                file_put_contents($command[2].'/tileset.json', '{"asset": {"version": "1.0"}}');
                file_put_contents($command[2].'/LOD-0/Mesh.b3dm', 'b3dm');
            }

            return $summary;
        },
    ]);
}

/**
 * A twin with a gateway token, and the token.
 *
 * @return array{0: Twin, 1: string}
 */
function gatewayTwin(): array
{
    [, $organisation] = member();
    $twin = twinIn($organisation);

    return [$twin, resolve(IssueGatewayToken::class)->handle($twin)];
}

/**
 * One of Northshore's custom types, as the library would hold it.
 *
 * @return array<string, mixed>
 */
function northshoreType(string $id): array
{
    /** @var array{types: list<array<string, mixed>>} $definition */
    $definition = resolve(SampleTwins::class)->definition('northshore');

    return array_find($definition['types'], fn (array $type): bool => $type['id'] === $id) ?? throw new RuntimeException('No type '.$id);
}

const IDP_ISSUER = 'https://93.184.216.34/realms/harbour';

/**
 * An ID token as an identity provider's token endpoint gives it: unsigned
 * here, since Meridian takes its claims from that direct answer.
 *
 * @param  array<string, mixed>  $claims
 */
function idToken(array $claims): string
{
    $encode = fn (array $part): string => mb_rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

    return $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claims).'.signature';
}

/**
 * Fakes an OpenID Connect provider at IDP_ISSUER that signs in whoever the
 * claims say, for the given nonce.
 *
 * @param  array<string, mixed>  $claims
 */
function fakeIdentityProvider(array $claims = [], string $nonce = 'nonce-from-the-browser'): void
{
    Http::fake([
        IDP_ISSUER.'/.well-known/openid-configuration' => Http::response([
            'issuer' => IDP_ISSUER,
            'authorization_endpoint' => IDP_ISSUER.'/protocol/openid-connect/auth',
            'token_endpoint' => IDP_ISSUER.'/protocol/openid-connect/token',
        ]),
        IDP_ISSUER.'/protocol/openid-connect/token' => Http::response([
            'access_token' => 'access',
            'token_type' => 'Bearer',
            'id_token' => idToken([
                'iss' => IDP_ISSUER,
                'aud' => 'meridian',
                'exp' => now()->addMinutes(5)->getTimestamp(),
                'iat' => now()->getTimestamp(),
                'nonce' => $nonce,
                'sub' => 'ana-at-harbour',
                'email' => 'ana@harbour.example',
                'email_verified' => true,
                'name' => 'Ana Ruiz',
                ...$claims,
            ]),
        ]),
    ]);
}

/**
 * Stands in for meridian-vector: writes a GeoJSON layer and prints its summary.
 */
function fakeVectorConverter(?string $fail = null, bool $writes = true, string $summary = '{"crs": "EPSG:2193", "features": 1250, "layers": 2, "kinds": {"Polygon": 1200, "LineString": 50}, "bounds": [174.74, -36.86, 174.77, -36.84]}'): void
{
    Process::fake([
        "'meridian-vector'*" => function (PendingProcess $process) use ($fail, $writes, $summary): ProcessResult|string {
            if ($fail !== null) {
                return Process::result(errorOutput: $fail, exitCode: 2);
            }

            if ($writes) {
                /** @var list<string> $command */
                $command = $process->command;
                file_put_contents($command[2], '{"type":"FeatureCollection","features":[]}');
            }

            return $summary;
        },
    ]);
}

/**
 * Stands in for meridian-imagery: writes a tile and imagery.json, and prints its summary.
 */
function fakeImageryTiler(?string $fail = null, bool $writes = true, string $summary = '{"crs": "EPSG:2193", "width": 12000, "height": 8000, "tiles": 340, "minZoom": 12, "maxZoom": 20, "bounds": [174.74, -36.86, 174.77, -36.84]}'): void
{
    Process::fake([
        "'meridian-imagery'*" => function (PendingProcess $process) use ($fail, $writes, $summary): ProcessResult|string {
            if ($fail !== null) {
                return Process::result(errorOutput: $fail, exitCode: 2);
            }

            if ($writes) {
                /** @var list<string> $command */
                $command = $process->command;
                File::ensureDirectoryExists($command[2].'/20/1/');
                file_put_contents($command[2].'/imagery.json', '{"format": "png", "minZoom": 12, "maxZoom": 20}');
                file_put_contents($command[2].'/20/1/2.png', 'png');
            }

            return $summary;
        },
    ]);
}

/**
 * Stands in for meridian-volume: draws an image and prints the volumes,
 * unless $fail gives it error output to fail with. Each run's two surveys
 * are noted in $runs.
 *
 * @param  ArrayObject<int, array{0: string, 1: string}>|null  $runs
 */
function fakeVolumeTool(?string $fail = null, bool $draws = true, string $summary = '{"cut": 120.5, "fill": 450.25, "net": 329.75, "areaM2": 3000.0, "coveredM2": 2950.0, "cutM2": 400.0, "fillM2": 900.0, "deepestCut": 0.8, "highestFill": 2.4, "cell": 0.5, "bounds": [174.785, -36.8398, 174.7856, -36.8392]}', ?ArrayObject $runs = null): void
{
    Process::fake([
        "'meridian-volume'*" => function (PendingProcess $process) use ($fail, $draws, $summary, $runs): ProcessResult|string {
            /** @var list<string> $command */
            $command = $process->command;
            $runs?->append([(string) file_get_contents($command[1]), (string) file_get_contents($command[2])]);

            if ($fail !== null) {
                return Process::result(errorOutput: $fail, exitCode: 2);
            }

            if ($draws) {
                file_put_contents($command[3], "\x89PNG change");
            }

            return $summary;
        },
    ]);
}

<?php

declare(strict_types=1);

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

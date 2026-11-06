<?php

declare(strict_types=1);

use App\Actions\ExtractTilesetArchive;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => Storage::fake('local'));

function extractTileset(array $entries, array $symlinks = []): array
{
    return resolve(ExtractTilesetArchive::class)->handle(tilesetZip($entries, $symlinks)->getRealPath(), Storage::disk('local'), 'out');
}

it('unpacks a tileset at the top of the zip', function (): void {
    $result = extractTileset(['tileset.json' => tilesetJson(), 'tiles/' => '', 'tiles/0.glb' => 'abc', '__MACOSX/._x' => 'junk', 'tiles/.DS_Store' => 'junk']);

    expect($result)->toBe(['entry' => 'tileset.json', 'bytes' => mb_strlen(tilesetJson()) + 3, 'files' => 2])
        ->and(Storage::disk('local')->allFiles('out'))->toEqualCanonicalizing(['out/tileset.json', 'out/tiles/0.glb']);
});

it('unwraps a zipped folder', function (): void {
    extractTileset(['scan/tileset.json' => tilesetJson(), 'scan/tiles/0.pnts' => 'p']);

    expect(Storage::disk('local')->allFiles('out'))->toEqualCanonicalizing(['out/tileset.json', 'out/tiles/0.pnts']);
});

it('refuses unsafe archives', function (array $entries, string $message, array $symlinks = []): void {
    expect(fn (): array => extractTileset($entries, $symlinks))->toThrow(ValidationException::class, $message)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'parent path' => [['tileset.json' => '{}', '../evil.json' => '{}'], 'unsafe path: ../evil.json'],
    'absolute path' => [['tileset.json' => '{}', '/etc/x.json' => '{}'], 'unsafe path'],
    'backslash' => [['tileset.json' => '{}', 'a\\b.json' => '{}'], 'unsafe path'],
    'drive letter' => [['tileset.json' => '{}', 'C:x.json' => '{}'], 'unsafe path'],
    'dot segment' => [['tileset.json' => '{}', 'a/./b.json' => '{}'], 'unsafe path'],
    'symlink' => [['tileset.json' => tilesetJson(), 'link.json' => '/etc/passwd'], 'symbolic link: link.json', ['link.json']],
    'other files' => [['tileset.json' => tilesetJson(), 'run.php' => '<?php'], 'not part of a tileset: run.php'],
    'no root' => [['tiles/0.glb' => 'x', 'more/1.glb' => 'y'], 'needs a tileset.json'],
    'nested root' => [['a/b/tileset.json' => '{}'], 'needs a tileset.json'],
    'not a tileset' => [['tileset.json' => '{"asset":{}}'], 'needs "asset" and "root"'],
    'not json' => [['tileset.json' => 'nope'], 'needs "asset" and "root"'],
]);

it('refuses a zip with too many files', function (): void {
    config(['meridian.tileset.max_files' => 1]);

    extractTileset(['tileset.json' => tilesetJson(), 'a.glb' => 'x']);
})->throws(ValidationException::class, 'larger than this server accepts');

it('refuses a zip whose contents declare too many bytes', function (): void {
    config(['meridian.tileset.max_uncompressed_bytes' => 10]);

    extractTileset(['tileset.json' => tilesetJson()]);
})->throws(ValidationException::class, 'larger than this server accepts');

it('stops streaming once the real bytes exceed the budget', function (): void {
    $zip = tilesetZip(['tileset.json' => tilesetJson(), 'a.glb' => str_repeat('x', 100)]);
    config(['meridian.tileset.max_uncompressed_bytes' => mb_strlen(tilesetJson()) + 100]);
    $action = resolve(ExtractTilesetArchive::class);
    $reflection = new ReflectionMethod($action, 'copy');
    $archive = new ZipArchive();
    $archive->open($zip->getRealPath());

    expect(fn (): mixed => $reflection->invoke($action, $archive, $archive->locateName('a.glb'), Storage::disk('local'), 'out/a.glb', 50))
        ->toThrow(ValidationException::class, 'larger than this server accepts');
});

it('refuses a file that is not a zip', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, 'not a zip');

    resolve(ExtractTilesetArchive::class)->handle($path, Storage::disk('local'), 'out');
})->throws(ValidationException::class, 'not a readable zip file');

it('will not parse an oversized root tileset.json', function (): void {
    config(['meridian.tileset.max_root_json_bytes' => 10]);
    $action = resolve(ExtractTilesetArchive::class);
    $zip = tilesetZip(['tileset.json' => tilesetJson()]);
    $archive = new ZipArchive();
    $archive->open($zip->getRealPath());

    expect(fn (): mixed => new ReflectionMethod($action, 'checkRoot')->invoke($action, $archive, 0))
        ->toThrow(ValidationException::class, 'needs "asset" and "root"');
});

it('reports a zip whose entries cannot be read', function (): void {
    $broken = new class extends ZipArchive
    {
        public function statIndex(int $index, int $flags = 0): array|false
        {
            return false;
        }
    };
    $broken->open(tilesetZip(['tileset.json' => tilesetJson()])->getRealPath());

    $action = resolve(ExtractTilesetArchive::class);

    expect(fn (): mixed => new ReflectionMethod($action, 'entries')->invoke($action, $broken))
        ->toThrow(ValidationException::class, 'The zip is damaged.');
});

it('reports an entry that cannot be streamed', function (): void {
    $broken = new class extends ZipArchive
    {
        public function getStreamIndex(int $index, int $flags = 0)
        {
            return false;
        }
    };
    $broken->open(tilesetZip(['tileset.json' => tilesetJson()])->getRealPath());

    $action = resolve(ExtractTilesetArchive::class);

    expect(fn (): mixed => new ReflectionMethod($action, 'copy')->invoke($action, $broken, 0, Storage::disk('local'), 'out/x', 100))
        ->toThrow(ValidationException::class, 'The zip is damaged.');
});

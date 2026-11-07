<?php

declare(strict_types=1);

use App\Support\DirectUploads;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\File;

it('is on only for an s3 assets disk with the switch set', function (string $disk, bool $enabled, bool $expected): void {
    config(['meridian.assets_disk' => $disk, 'meridian.direct_uploads.enabled' => $enabled]);

    expect(DirectUploads::enabled())->toBe($expected);
})->with([
    ['s3', true, true],
    ['s3', false, false],
    ['local', true, false],
]);

it('signs part URLs for the endpoint browsers reach, not the one the app uses', function (): void {
    $uploads = DirectUploads::fromConfig(
        ['key' => 'k', 'secret' => 's', 'region' => 'us-east-1', 'bucket' => 'meridian', 'endpoint' => 'http://rustfs:9000', 'use_path_style_endpoint' => true],
        'http://localhost:9014',
        8 * 1024 * 1024,
    );

    $url = $uploads->signPart('uploads/a/scan.zip', 'up-1', 3);

    expect($url)->toStartWith('http://localhost:9014/meridian/uploads/a/scan.zip?')
        ->and($url)->toContain('partNumber=3')
        ->and($url)->toContain('uploadId=up-1')
        ->and($url)->toContain('X-Amz-Signature=')
        ->and($uploads->partBytes())->toBe(8 * 1024 * 1024);
});

it('signs against the app endpoint, or AWS itself, when no public one is set', function (): void {
    $docker = DirectUploads::fromConfig(['bucket' => 'meridian', 'endpoint' => 'http://rustfs:9000', 'use_path_style_endpoint' => true], null, 1);
    $aws = DirectUploads::fromConfig(['bucket' => 'meridian', 'region' => 'eu-west-2'], '', 1);

    expect($docker->signPart('k', 'u', 1))->toStartWith('http://rustfs:9000/meridian/k?')
        ->and($aws->signPart('k', 'u', 1))->toStartWith('https://meridian.s3.eu-west-2.amazonaws.com/k?');
});

it('starts, completes, aborts and deletes uploads in the bucket', function (): void {
    $sent = fakeDirectUploads(['CreateMultipartUpload' => new Result(['UploadId' => 'up-1'])]);
    $uploads = resolve(DirectUploads::class);

    expect($uploads->start('uploads/a/scan.zip'))->toBe('up-1');
    $uploads->complete('uploads/a/scan.zip', 'up-1', [['PartNumber' => 1, 'ETag' => '"e1"']]);
    $uploads->abort('uploads/b/x.glb', 'up-2');
    $uploads->delete('uploads/a/scan.zip');

    expect(array_map(fn (CommandInterface $command): string => $command->getName(), $sent->getArrayCopy()))
        ->toBe(['CreateMultipartUpload', 'CompleteMultipartUpload', 'AbortMultipartUpload', 'DeleteObject'])
        ->and($sent[0]['Bucket'])->toBe('meridian')
        ->and($sent[1]['MultipartUpload'])->toBe(['Parts' => [['PartNumber' => 1, 'ETag' => '"e1"']]])
        ->and($sent[2]['UploadId'])->toBe('up-2');
});

it('refuses to go on when the store opens no upload', function (): void {
    fakeDirectUploads(['CreateMultipartUpload' => new Result([])]);

    resolve(DirectUploads::class)->start('uploads/a/x.glb');
})->throws(RuntimeException::class, 'did not open a multipart upload');

it('streams an object to a local file', function (): void {
    fakeDirectUploads(['GetObject' => new Result(['Body' => Utils::streamFor('glTF bytes')])]);
    $path = (string) tempnam(sys_get_temp_dir(), 'dl');

    resolve(DirectUploads::class)->download('uploads/a/x.glb', $path);

    expect(file_get_contents($path))->toBe('glTF bytes');
    File::delete($path);
});

it('refuses a download with no body', function (): void {
    fakeDirectUploads(['GetObject' => new Result([])]);

    resolve(DirectUploads::class)->download('uploads/a/x.glb', (string) tempnam(sys_get_temp_dir(), 'dl'));
})->throws(RuntimeException::class, 'no body');

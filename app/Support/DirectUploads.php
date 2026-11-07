<?php

declare(strict_types=1);

namespace App\Support;

use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Multipart uploads straight from the browser to the S3-compatible assets
 * bucket, so large files never pass through PHP. The control plane starts
 * and completes each upload and signs a URL per part; the browser PUTs the
 * parts. Signing uses the endpoint browsers can reach, which inside Docker is
 * not the one the app talks to.
 */
final readonly class DirectUploads
{
    public function __construct(
        private S3Client $client,
        private S3Client $signer,
        private string $bucket,
        private int $partBytes,
    ) {}

    /**
     * @param  array<string, mixed>  $disk  The s3 disk's configuration.
     */
    public static function fromConfig(array $disk, ?string $publicEndpoint, int $partBytes): self
    {
        $text = fn (string $key): string => is_string($disk[$key] ?? null) ? $disk[$key] : '';
        $options = [
            'version' => 'latest',
            'region' => $text('region') !== '' ? $text('region') : 'us-east-1',
            'use_path_style_endpoint' => (bool) ($disk['use_path_style_endpoint'] ?? false),
            'credentials' => ['key' => $text('key'), 'secret' => $text('secret')],
        ];
        $endpoint = $text('endpoint') !== '' ? $text('endpoint') : null;
        $public = $publicEndpoint !== null && $publicEndpoint !== '' ? $publicEndpoint : $endpoint;

        return new self(
            new S3Client($endpoint === null ? $options : [...$options, 'endpoint' => $endpoint]),
            new S3Client($public === null ? $options : [...$options, 'endpoint' => $public]),
            $text('bucket'),
            $partBytes,
        );
    }

    /**
     * Whether uploads go straight to the bucket: only for an s3 assets disk,
     * and only when switched on, since the bucket needs a CORS rule for it.
     */
    public static function enabled(): bool
    {
        $disk = config()->string('meridian.assets_disk');

        return config()->boolean('meridian.direct_uploads.enabled')
            && config('filesystems.disks.'.$disk.'.driver') === 's3';
    }

    public function partBytes(): int
    {
        return $this->partBytes;
    }

    public function start(string $key): string
    {
        $uploadId = $this->client->createMultipartUpload(['Bucket' => $this->bucket, 'Key' => $key])->get('UploadId');

        throw_unless(is_string($uploadId), RuntimeException::class, 'The store did not open a multipart upload.');

        return $uploadId;
    }

    public function signPart(string $key, string $uploadId, int $partNumber): string
    {
        $command = $this->signer->getCommand('UploadPart', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        return (string) $this->signer->createPresignedRequest($command, '+1 hour')->getUri();
    }

    /**
     * @param  list<array{PartNumber: int, ETag: string}>  $parts
     */
    public function complete(string $key, string $uploadId, array $parts): void
    {
        $this->client->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);
    }

    public function abort(string $key, string $uploadId): void
    {
        $this->client->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId]);
    }

    /**
     * Streams the uploaded object to a local file, for checking and unpacking.
     */
    public function download(string $key, string $path): void
    {
        $body = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key])->get('Body');

        throw_unless($body instanceof StreamInterface, RuntimeException::class, 'The store returned no body for '.$key);

        Utils::copyToStream($body, Utils::streamFor(Utils::tryFopen($path, 'wb')));
    }

    public function delete(string $key): void
    {
        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
    }
}

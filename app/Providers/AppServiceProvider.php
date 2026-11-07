<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\DirectUploads;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(function (): DirectUploads {
            /** @var array<string, mixed> $disk */
            $disk = config()->array('filesystems.disks.'.config()->string('meridian.assets_disk'));
            $publicEndpoint = config('meridian.direct_uploads.public_endpoint');

            return DirectUploads::fromConfig(
                $disk,
                is_string($publicEndpoint) ? $publicEndpoint : null,
                config()->integer('meridian.direct_uploads.part_mb') * 1024 * 1024,
            );
        });
    }
}

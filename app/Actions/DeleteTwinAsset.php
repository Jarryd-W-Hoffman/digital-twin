<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TwinAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class DeleteTwinAsset
{
    public function __construct(private SaveTwinVersion $versions) {}

    /**
     * Removes the files and, if the latest version still shows the asset, saves
     * a version without it. Older versions keep their reference; the viewer
     * skips layers that no longer load.
     */
    public function handle(TwinAsset $asset, User $user): void
    {
        $twin = $asset->twin;

        DB::transaction(function () use ($asset, $user, $twin): void {
            $definition = $twin->latestVersion()->firstOrFail()->definition;
            $changed = false;

            foreach (['tilesets', 'models'] as $field) {
                $layers = is_array($definition[$field] ?? null) ? array_values($definition[$field]) : [];
                $kept = array_values(array_filter($layers, fn (mixed $layer): bool => ! is_array($layer) || ($layer['assetId'] ?? null) !== $asset->id));
                $changed = $changed || count($kept) !== count($layers);
                $definition[$field] = $kept;
            }

            if ($changed) {
                $this->versions->handle($twin, $user, $definition, __('Removed :name', ['name' => $asset->name]));
            }

            $asset->delete();
        });

        Storage::disk(config()->string('meridian.assets_disk'))->deleteDirectory($asset->directory());
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ProjectAsset;
use App\Models\Twin;
use App\Models\TwinVersion;
use App\Models\User;

final readonly class RemoveAssetFromTwin
{
    public function __construct(private SaveTwinVersion $versions) {}

    /**
     * Takes an upload's layers out of the twin as a new version; the files stay
     * in the project's library. Null when the latest version doesn't show it.
     * Older versions keep their reference.
     */
    public function handle(Twin $twin, ?User $user, ProjectAsset $asset): ?TwinVersion
    {
        $definition = $twin->latestVersion()->firstOrFail()->definition;
        $terrain = $definition['terrain'] ?? null;
        $changed = is_array($terrain) && ($terrain['assetId'] ?? null) === $asset->id;

        if ($changed) {
            unset($definition['terrain']);
        }

        foreach (['tilesets', 'models'] as $field) {
            $layers = is_array($definition[$field] ?? null) ? array_values($definition[$field]) : [];
            $kept = array_values(array_filter($layers, fn (mixed $layer): bool => ! is_array($layer) || ($layer['assetId'] ?? null) !== $asset->id));
            $changed = $changed || count($kept) !== count($layers);
            $definition[$field] = $kept;
        }

        return $changed
            ? $this->versions->handle($twin, $user, $definition, __('Removed :name', ['name' => $asset->name]))
            : null;
    }
}

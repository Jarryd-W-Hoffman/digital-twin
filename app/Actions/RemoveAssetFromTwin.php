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
     * Takes an upload's layers out of the twin as a new version, unbinding any
     * assets from their features; the files stay in the project's library. Null when the latest version doesn't show it.
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

        $removed = [];

        foreach (['tilesets', 'models', 'vectors', 'imagery'] as $field) {
            $layers = is_array($definition[$field] ?? null) ? array_values($definition[$field]) : [];
            $kept = [];

            foreach ($layers as $layer) {
                if (is_array($layer) && ($layer['assetId'] ?? null) === $asset->id) {
                    $removed[] = $layer['id'] ?? null;
                } else {
                    $kept[] = $layer;
                }
            }

            $changed = $changed || $removed !== [];

            if (array_key_exists($field, $definition)) {
                $definition[$field] = $kept;
            }
        }

        // Assets that were features of a removed layer go back to their own bodies.
        if ($removed !== [] && is_array($definition['assets'] ?? null)) {
            $definition['assets'] = array_map(function (mixed $item) use ($removed): mixed {
                if (is_array($item) && is_array($item['feature'] ?? null) && in_array($item['feature']['layer'] ?? null, $removed, true)) {
                    unset($item['feature']);
                }

                return $item;
            }, $definition['assets']);
        }

        return $changed
            ? $this->versions->handle($twin, $user, $definition, __('Removed :name', ['name' => $asset->name]))
            : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveShareLink;
use App\Models\ProjectAsset;
use App\Models\TwinShareLink;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A twin shown through a share link, read only, to someone who needn't be
 * a member: its latest version, its opening view, and (when the link allows)
 * the gateway to follow it live and its recorded history.
 */
final readonly class SharedTwinController
{
    public function show(Request $request, string $token): Response
    {
        $link = ResolveShareLink::link($request);
        $link->viewed();

        $twin = $link->twin;

        return Inertia::render('share/show', [
            'twin' => ['name' => $twin->name, 'description' => $twin->description],
            'organisation' => $twin->organisation->name,
            'token' => $token,
            'live' => $link->live,
            'expiresAt' => $link->expires_at->toIso8601String(),
            'definition' => $this->definition($link, $token),
            'startView' => $twin->openingView?->state,
            'captures' => $this->captures($link),
        ]);
    }

    /**
     * When each dated upload the twin shows was captured, and what it is.
     *
     * @return array<string, array{on: string, kind: string}>
     */
    private function captures(TwinShareLink $link): array
    {
        return $link->twin->project->assets()->whereKey($link->assetIds())->whereNotNull('captured_on')->get()
            ->mapWithKeys(fn (ProjectAsset $asset): array => [$asset->id => ['on' => (string) $asset->captured_on?->toDateString(), 'kind' => $asset->kind->value]])
            ->all();
    }

    /**
     * The latest definition, its library files loaded through the link.
     *
     * @return array<mixed>
     */
    private function definition(TwinShareLink $link, string $token): array
    {
        $definition = $link->twin->latestVersion()->firstOrFail()->definition;
        $through = fn (mixed $layer): mixed => is_array($layer) && is_string($layer['assetId'] ?? null) && is_string($layer['url'] ?? null)
            ? [...$layer, 'url' => str_replace('/assets/'.$layer['assetId'].'/files/', '/share/'.$token.'/assets/'.$layer['assetId'].'/files/', $layer['url'])]
            : $layer;

        foreach (['tilesets', 'models', 'vectors', 'imagery', 'terrains'] as $field) {
            if (is_array($definition[$field] ?? null)) {
                $definition[$field] = array_map($through, $definition[$field]);
            }
        }

        return $definition;
    }
}

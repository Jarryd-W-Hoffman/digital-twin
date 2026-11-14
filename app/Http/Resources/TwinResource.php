<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Twin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A twin, as the API shows it: its name, project and gateway, and on its
 * own also its latest version's number and assets, and what's open.
 *
 * @property-read Twin $resource
 */
final class TwinResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $twin = $this->resource;
        $definition = $twin->relationLoaded('latestVersion') ? $twin->latestVersion?->definition : null;

        return [
            'id' => $twin->id,
            'name' => $twin->name,
            'description' => $twin->description,
            'projectId' => $twin->project_id,
            'version' => $this->when($twin->relationLoaded('latestVersion'), fn (): ?int => $twin->latestVersion?->number),
            'gateway' => [
                'connected' => $twin->gateway_token_hash !== null,
                'online' => $twin->gatewayOnline(),
                'seenAt' => $twin->gateway_seen_at?->toIso8601String(),
            ],
            'openAlerts' => $this->whenCounted('openAlerts'),
            'openIssues' => $this->whenCounted('openIssues'),
            'assets' => $this->when(is_array($definition), fn (): array => $this->assets(is_array($definition) ? $definition : [])),
            'createdAt' => $twin->created_at->toIso8601String(),
            'updatedAt' => $twin->updated_at->toIso8601String(),
        ];
    }

    /**
     * The twin's assets from its latest version: what readings, alerts and
     * issues name them by.
     *
     * @param  array<mixed>  $definition
     * @return list<array{id: string, name: string, kind: string, zone: string|null}>
     */
    private function assets(array $definition): array
    {
        $assets = is_array($definition['assets'] ?? null) ? $definition['assets'] : [];

        return array_values(array_map(fn (array $asset): array => [
            'id' => (string) $asset['id'],
            'name' => is_string($asset['name'] ?? null) ? $asset['name'] : (string) $asset['id'],
            'kind' => is_string($asset['kind'] ?? null) ? $asset['kind'] : '',
            'zone' => is_string($asset['zone'] ?? null) ? $asset['zone'] : null,
        ], array_filter($assets, fn (mixed $asset): bool => is_array($asset) && is_string($asset['id'] ?? null))));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An alert from a twin's log, as the API shows it.
 *
 * @property-read Alert $resource
 */
final class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $alert = $this->resource;

        return [
            'id' => $alert->id,
            'twinId' => $alert->twin_id,
            'rule' => $alert->rule,
            'asset' => $alert->asset,
            'severity' => $alert->severity->value,
            'message' => $alert->message,
            'value' => $alert->value,
            'raisedAt' => $alert->raised_at->toIso8601String(),
            'clearedAt' => $alert->cleared_at?->toIso8601String(),
            'acknowledgedAt' => $alert->acknowledged_at?->toIso8601String(),
            'acknowledgedBy' => $alert->acknowledgerName(),
        ];
    }
}

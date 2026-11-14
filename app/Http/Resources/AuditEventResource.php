<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry in an organisation's audit log.
 *
 * @property-read AuditEvent $resource
 */
final class AuditEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = $this->resource;

        return [
            'id' => $event->id,
            'at' => $event->created_at->toIso8601String(),
            'actor' => $event->actorLabel(),
            'actorId' => $event->actor_id,
            'viaApi' => $event->actor_api !== null,
            'action' => $event->action->value,
            'label' => $event->action->label(),
            'area' => $event->action->area(),
            'subject' => $event->subject,
            'project' => $event->project_id === null ? null : ['id' => $event->project_id, 'name' => $event->project_name],
            'twin' => $event->twin_id === null ? null : ['id' => $event->twin_id, 'name' => $event->twin_name],
            'details' => $event->details ?? (object) [],
            'ip' => $event->ip,
        ];
    }
}

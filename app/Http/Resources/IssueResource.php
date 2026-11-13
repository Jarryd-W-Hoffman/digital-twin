<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Issue;
use App\Models\IssueComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An issue pinned in a twin, as the API shows it, with its comments when
 * they're loaded.
 *
 * @property-read Issue $resource
 */
final class IssueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $issue = $this->resource;

        return [
            'id' => $issue->id,
            'twinId' => $issue->twin_id,
            'number' => $issue->number,
            'title' => $issue->title,
            'at' => ['lon' => $issue->lon, 'lat' => $issue->lat, 'height' => $issue->height],
            'author' => $issue->author?->name,
            'assignee' => $issue->assignee?->name,
            'dueOn' => $issue->due_on?->format('Y-m-d'),
            'overdue' => $issue->isOverdue(),
            'alertId' => $issue->alert_id,
            'createdAt' => $issue->created_at->toIso8601String(),
            'updatedAt' => $issue->updated_at->toIso8601String(),
            'closedAt' => $issue->closed_at?->toIso8601String(),
            'closedBy' => $issue->closer?->name,
            'url' => route('twins.show', [$issue->twin_id, 'issue' => $issue->id]),
            'comments' => $this->whenLoaded('comments', fn (): array => $issue->comments->map(fn (IssueComment $comment): array => [
                'id' => $comment->id,
                'author' => $comment->author?->name,
                'body' => $comment->body,
                'photo' => $comment->photo === null ? null : route('api.v1.twins.issues.comments.photo', [$issue->twin_id, $issue->id, $comment->id]),
                'createdAt' => $comment->created_at->toIso8601String(),
            ])->all()),
        ];
    }
}

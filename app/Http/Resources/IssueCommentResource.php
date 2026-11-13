<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IssueComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One comment on an issue, with a link to its photo through the API.
 *
 * @property-read IssueComment $resource
 */
final class IssueCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $comment = $this->resource;

        return [
            'id' => $comment->id,
            'author' => $comment->authorName(),
            'body' => $comment->body,
            'photo' => $comment->photo === null ? null : route('api.v1.twins.issues.comments.photo', [$comment->issue->twin_id, $comment->issue_id, $comment->id]),
            'createdAt' => $comment->created_at->toIso8601String(),
        ];
    }
}

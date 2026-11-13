<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Issue;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Pins a new issue to a place in a twin, numbered after the twin's last,
 * with what its author wrote and photographed as the first comment.
 */
final readonly class CreateIssue
{
    public function __construct(private AddIssueComment $comments)
    {
        //
    }

    /**
     * @param  array{lon: float, lat: float, height: float}  $at
     * @param  array{lon: float, lat: float, height: float, heading: float, pitch: float, roll: float}  $view
     */
    public function handle(Twin $twin, User $author, string $title, array $at, array $view, ?string $body = null, ?UploadedFile $photo = null): Issue
    {
        return DB::transaction(function () use ($twin, $author, $title, $at, $view, $body, $photo): Issue {
            Twin::query()->whereKey($twin->id)->lockForUpdate()->first();

            $last = $twin->issues()->max('number');

            $issue = $twin->issues()->create([
                'number' => (is_numeric($last) ? (int) $last : 0) + 1,
                'title' => $title,
                ...$at,
                'view' => $view,
                'author_id' => $author->id,
            ]);
            $issue->setRelation('twin', $twin);

            if ($body !== null || $photo instanceof UploadedFile) {
                $this->comments->handle($issue, $author, $body, $photo);
            }

            return $issue;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\IssueEvent;
use App\Models\Alert;
use App\Models\Issue;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Pins a new issue to a place in a twin, numbered after the twin's last,
 * with what its author wrote and photographed as the first comment, and
 * the alert it follows up if there is one. Channels that take issue updates
 * are told.
 */
final readonly class CreateIssue
{
    public function __construct(private AddIssueComment $comments, private NotifyIssue $notify)
    {
        //
    }

    /**
     * @param  array{lon: float, lat: float, height: float}  $at
     * @param  array{lon: float, lat: float, height: float, heading: float, pitch: float, roll: float}  $view
     */
    public function handle(Twin $twin, User $author, string $title, array $at, array $view, ?string $body = null, ?UploadedFile $photo = null, ?Alert $alert = null): Issue
    {
        $issue = DB::transaction(function () use ($twin, $author, $title, $at, $view, $body, $photo, $alert): Issue {
            Twin::query()->whereKey($twin->id)->lockForUpdate()->first();

            $last = $twin->issues()->max('number');

            $issue = $twin->issues()->create([
                'number' => (is_numeric($last) ? (int) $last : 0) + 1,
                'title' => $title,
                ...$at,
                'view' => $view,
                'author_id' => $author->id,
                'alert_id' => $alert?->id,
            ]);
            $issue->setRelation('twin', $twin);

            if ($body !== null || $photo instanceof UploadedFile) {
                $this->comments->handle($issue, $author, $body, $photo);
            }

            return $issue;
        });

        $this->notify->handle($issue, IssueEvent::Raised, $author);

        return $issue;
    }
}

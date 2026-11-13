<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\IssueEvent;
use App\Mail\IssueMail;
use App\Models\ApiToken;
use App\Models\Issue;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * What a channel is told of an issue: raised, given to someone, or closed,
 * by whom, with a link that opens the twin at it.
 */
final readonly class IssueMessage implements ChannelMessage
{
    public function __construct(
        public IssueEvent $event,
        public string $issueId,
        public int $number,
        public string $title,
        public string $twin,
        public string $project,
        public ?string $by,
        public ?string $assignee,
        public ?CarbonInterface $dueOn,
        public ?string $alertId,
        public string $url,
    ) {}

    public static function about(Issue $issue, IssueEvent $event, User|ApiToken|null $by): self
    {
        $twin = $issue->twin;

        return new self(
            event: $event,
            issueId: $issue->id,
            number: $issue->number,
            title: $issue->title,
            twin: $twin->name,
            project: $twin->project->name,
            by: $by === null ? null : Actor::name($by),
            assignee: $issue->assignee?->name,
            dueOn: $issue->due_on,
            alertId: $issue->alert_id,
            url: route('twins.show', [$twin, 'issue' => $issue->id]),
        );
    }

    public function subject(): string
    {
        $replace = ['number' => $this->number, 'title' => $this->title, 'twin' => $this->twin, 'assignee' => $this->assignee ?? __('nobody')];

        return match ($this->event) {
            IssueEvent::Raised => __('Issue #:number raised: :title (:twin)', $replace),
            IssueEvent::Assigned => __('Issue #:number assigned to :assignee: :title (:twin)', $replace),
            IssueEvent::Closed => __('Issue #:number closed: :title (:twin)', $replace),
        };
    }

    public function detail(): string
    {
        $parts = [$this->project];
        if ($this->by !== null) {
            $parts[] = __('by :name', ['name' => $this->by]);
        }

        if ($this->dueOn instanceof CarbonInterface && $this->event !== IssueEvent::Closed) {
            $parts[] = __('due :date', ['date' => $this->dueOn->format('Y-m-d')]);
        }

        return implode(' · ', $parts);
    }

    public function link(): string
    {
        return $this->url;
    }

    public function linkLabel(): string
    {
        return __('Open the issue');
    }

    public function event(): string
    {
        return 'issue.'.$this->event->value;
    }

    public function mark(): string
    {
        return match ($this->event) {
            IssueEvent::Raised => ':round_pushpin:',
            IssueEvent::Assigned => ':bust_in_silhouette:',
            IssueEvent::Closed => ':white_check_mark:',
        };
    }

    public function tone(): string
    {
        return match ($this->event) {
            IssueEvent::Closed => 'Good',
            default => 'Accent',
        };
    }

    public function mail(): IssueMail
    {
        return new IssueMail($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'event' => $this->event(),
            'issue' => [
                'id' => $this->issueId,
                'number' => $this->number,
                'title' => $this->title,
                'assignee' => $this->assignee,
                'dueOn' => $this->dueOn?->format('Y-m-d'),
                'alertId' => $this->alertId,
            ],
            'by' => $this->by,
            'twin' => $this->twin,
            'project' => $this->project,
            'url' => $this->url,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AlertEvent;
use App\Enums\AlertSeverity;
use App\Models\Alert;
use App\Models\NotificationChannel;
use Carbon\CarbonInterface;

/**
 * What a notification says, whichever channel carries it: an alert raised
 * or cleared on a twin, with a link to the twin's alert log.
 */
final readonly class AlertMessage
{
    public function __construct(
        public AlertEvent $event,
        public AlertSeverity $severity,
        public string $message,
        public string $twin,
        public string $project,
        public string $asset,
        public string $rule,
        public float $value,
        public CarbonInterface $raisedAt,
        public ?CarbonInterface $clearedAt,
        public string $url,
        public ?string $alertId = null,
        public bool $test = false,
    ) {}

    public static function about(Alert $alert, AlertEvent $event): self
    {
        $twin = $alert->twin;

        return new self(
            event: $event,
            severity: $alert->severity,
            message: $alert->message,
            twin: $twin->name,
            project: $twin->project->name,
            asset: $alert->asset,
            rule: $alert->rule,
            value: $alert->value,
            raisedAt: $alert->raised_at,
            clearedAt: $alert->cleared_at,
            url: route('twins.alerts.index', $twin),
            alertId: $alert->id,
        );
    }

    /**
     * A made-up alert, to check a channel works.
     */
    public static function test(NotificationChannel $channel): self
    {
        $project = $channel->project;

        return new self(
            event: AlertEvent::Raised,
            severity: AlertSeverity::Info,
            message: __('A test from Meridian: :channel works.', ['channel' => $channel->name]),
            twin: __('No twin'),
            project: $project->name,
            asset: 'test',
            rule: 'test',
            value: 0,
            raisedAt: now(),
            clearedAt: null,
            url: route('projects.show', $project),
            test: true,
        );
    }

    /**
     * One line, for a subject or a chat message's first line.
     */
    public function subject(): string
    {
        return $this->event === AlertEvent::Cleared
            ? __('Cleared: :message (:twin)', ['message' => $this->message, 'twin' => $this->twin])
            : __(':severity: :message (:twin)', ['severity' => ucfirst($this->severity->value), 'message' => $this->message, 'twin' => $this->twin]);
    }

    /**
     * When it was raised, and when it cleared and after how long.
     */
    public function timing(): string
    {
        $raised = __('Raised :time UTC', ['time' => $this->raisedAt->copy()->utc()->format('Y-m-d H:i')]);

        return $this->clearedAt instanceof CarbonInterface
            ? $raised.', '.__('cleared after :duration', ['duration' => $this->raisedAt->diffForHumans($this->clearedAt, syntax: CarbonInterface::DIFF_ABSOLUTE)])
            : $raised;
    }

    /**
     * The webhook body: the alert as data.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'event' => $this->test ? 'test' : 'alert.'.$this->event->value,
            'alert' => [
                'id' => $this->alertId,
                'severity' => $this->severity->value,
                'message' => $this->message,
                'asset' => $this->asset,
                'rule' => $this->rule,
                'value' => $this->value,
                'raisedAt' => $this->raisedAt->toIso8601String(),
                'clearedAt' => $this->clearedAt?->toIso8601String(),
            ],
            'twin' => $this->twin,
            'project' => $this->project,
            'url' => $this->url,
        ];
    }
}

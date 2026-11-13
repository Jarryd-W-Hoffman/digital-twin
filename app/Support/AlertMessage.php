<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AlertEvent;
use App\Enums\AlertSeverity;
use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\NotificationChannel;
use Carbon\CarbonInterface;

/**
 * What a notification says, whichever channel carries it: an alert raised,
 * still unacknowledged, or cleared on a twin, with a link to the twin's
 * alert log.
 */
final readonly class AlertMessage implements ChannelMessage
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
        $replace = ['severity' => ucfirst($this->severity->value), 'message' => $this->message, 'twin' => $this->twin];

        return match ($this->event) {
            AlertEvent::Cleared => __('Cleared: :message (:twin)', $replace),
            AlertEvent::Escalated => __('Not acknowledged: :severity: :message (:twin)', $replace),
            AlertEvent::Raised => __(':severity: :message (:twin)', $replace),
        };
    }

    /**
     * When it was raised, and when it cleared and after how long.
     */
    public function timing(): string
    {
        $raised = __('Raised :time UTC', ['time' => $this->raisedAt->copy()->utc()->format('Y-m-d H:i')]);

        if ($this->clearedAt instanceof CarbonInterface) {
            return $raised.', '.__('cleared after :duration', ['duration' => $this->raisedAt->diffForHumans($this->clearedAt, syntax: CarbonInterface::DIFF_ABSOLUTE)]);
        }

        return $this->event === AlertEvent::Escalated
            ? $raised.', '.__('still open and not acknowledged after :duration', ['duration' => $this->raisedAt->diffForHumans(now(), syntax: CarbonInterface::DIFF_ABSOLUTE)])
            : $raised;
    }

    public function detail(): string
    {
        return $this->project.' · '.$this->timing();
    }

    public function link(): string
    {
        return $this->url;
    }

    public function linkLabel(): string
    {
        return $this->test ? __('Open the project') : __('Open the alert log');
    }

    public function event(): string
    {
        return $this->test ? 'test' : 'alert.'.$this->event->value;
    }

    public function mark(): string
    {
        return match (true) {
            $this->event === AlertEvent::Cleared => ':white_check_mark:',
            $this->event === AlertEvent::Escalated => ':rotating_light:',
            $this->severity === AlertSeverity::Critical => ':red_circle:',
            $this->severity === AlertSeverity::Warning => ':large_orange_circle:',
            default => ':large_blue_circle:',
        };
    }

    public function tone(): string
    {
        return match (true) {
            $this->event === AlertEvent::Cleared => 'Good',
            $this->event === AlertEvent::Escalated, $this->severity === AlertSeverity::Critical => 'Attention',
            default => 'Warning',
        };
    }

    public function mail(): AlertMail
    {
        return new AlertMail($this);
    }

    /**
     * The webhook body: the alert as data.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'event' => $this->event(),
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

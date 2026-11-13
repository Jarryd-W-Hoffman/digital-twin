<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertEvent;
use App\Enums\AlertNotificationStatus;
use App\Enums\AlertSeverity;
use App\Enums\NotificationChannelKind;
use Carbon\CarbonInterface;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somewhere a project's alerts are sent: a list of email addresses, or a
 * webhook (plain, Slack or Teams). It takes alerts of at least a severity,
 * optionally their clearing too, and outside its quiet hours only critical
 * ones. An escalation channel is told of an alert only once it has gone
 * unacknowledged for its delay, and of its clearing only if it was told.
 * It can also be told when issues are raised, assigned and closed.
 *
 * @property-read string $id
 * @property-read string $project_id
 * @property-read string $name
 * @property-read NotificationChannelKind $kind
 * @property-read string $target
 * @property-read string|null $secret
 * @property-read AlertSeverity $min_severity
 * @property-read bool $notify_cleared
 * @property-read bool $notify_issues
 * @property-read int|null $escalate_after_minutes
 * @property-read string|null $quiet_from
 * @property-read string|null $quiet_until
 * @property-read string $timezone
 * @property-read CarbonInterface|null $last_sent_at
 * @property-read string|null $last_error
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Project $project
 */
final class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'project_id' => 'string',
            'name' => 'string',
            'kind' => NotificationChannelKind::class,
            'target' => 'encrypted',
            'secret' => 'encrypted',
            'min_severity' => AlertSeverity::class,
            'notify_cleared' => 'boolean',
            'notify_issues' => 'boolean',
            'escalate_after_minutes' => 'integer',
            'quiet_from' => 'string',
            'quiet_until' => 'string',
            'timezone' => 'string',
            'last_sent_at' => 'datetime',
            'last_error' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Whether it's told only of alerts nobody has acknowledged in time.
     */
    public function escalates(): bool
    {
        return $this->escalate_after_minutes !== null;
    }

    /**
     * Whether this channel is told about an alert of a severity being raised,
     * escalated or cleared at a moment: null when it isn't for this channel
     * at all, Quiet when its quiet hours hold it back, otherwise Queued. An
     * escalation channel takes escalations rather than raises; whether it
     * was told, and so hears of the clearing, is for the caller to know.
     */
    public function decide(AlertSeverity $severity, AlertEvent $event, CarbonInterface $at): ?AlertNotificationStatus
    {
        if (! $severity->atLeast($this->min_severity)) {
            return null;
        }

        if ($event === ($this->escalates() ? AlertEvent::Raised : AlertEvent::Escalated)) {
            return null;
        }

        if ($event === AlertEvent::Cleared && ! $this->notify_cleared) {
            return null;
        }

        if ($severity !== AlertSeverity::Critical && $this->isQuietAt($at)) {
            return AlertNotificationStatus::Quiet;
        }

        return AlertNotificationStatus::Queued;
    }

    /**
     * Whether a moment falls in the quiet hours, in the channel's own time
     * zone. The hours may run past midnight, such as 22:00 to 07:00.
     */
    public function isQuietAt(CarbonInterface $at): bool
    {
        if ($this->quiet_from === null || $this->quiet_until === null) {
            return false;
        }

        $time = $at->copy()->setTimezone($this->timezone)->format('H:i');

        return $this->quiet_from <= $this->quiet_until
            ? $time >= $this->quiet_from && $time < $this->quiet_until
            : $time >= $this->quiet_from || $time < $this->quiet_until;
    }

    /**
     * The email addresses of an email channel, one per line or comma.
     *
     * @return list<string>
     */
    public function addresses(): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/[\s,;]+/', $this->target) ?: [])));
    }

    /**
     * What the project page shows of where it sends: the addresses, or only
     * a webhook's host, since its URL is a secret.
     */
    public function displayTarget(): string
    {
        return $this->kind === NotificationChannelKind::Email
            ? implode(', ', $this->addresses())
            : (parse_url($this->target, PHP_URL_HOST) ?: '').'/…';
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AlertSeverity;
use App\Enums\AuditAction;
use App\Enums\NotificationChannelKind;
use App\Enums\ReportFrequency;
use App\Models\NotificationChannel;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * Adds somewhere a project's alerts are sent. A plain webhook gets its own
 * signing secret, so its receiver can tell Meridian's requests from anyone
 * else's.
 */
final readonly class CreateNotificationChannel
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * @param  array{name: string, kind: NotificationChannelKind, target: string, min_severity: AlertSeverity, notify_cleared: bool, notify_issues: bool, report: ReportFrequency|null, escalate_after_minutes: int|null, quiet_from: string|null, quiet_until: string|null, timezone: string}  $channel
     */
    public function handle(Project $project, array $channel): NotificationChannel
    {
        $created = $project->notificationChannels()->create([
            ...$channel,
            'secret' => $channel['kind'] === NotificationChannelKind::Webhook ? 'whsec_'.Str::random(40) : null,
        ]);

        $this->audit->handle(AuditAction::ChannelAdded, $project, $channel['name'], ['kind' => $channel['kind']->value]);

        return $created;
    }
}

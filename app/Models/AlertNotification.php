<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertEvent;
use App\Enums\AlertNotificationStatus;
use Carbon\CarbonInterface;
use Database\Factories\AlertNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One alert's raising or clearing sent to one channel, or held back, and
 * how it went: the record behind "who was told".
 *
 * @property-read string $id
 * @property-read string $alert_id
 * @property-read string|null $notification_channel_id
 * @property-read string $channel_name
 * @property-read AlertEvent $event
 * @property-read AlertNotificationStatus $status
 * @property-read string|null $error
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface|null $attempted_at
 * @property-read Alert $alert
 * @property-read NotificationChannel|null $channel
 */
#[WithoutTimestamps]
final class AlertNotification extends Model
{
    /** @use HasFactory<AlertNotificationFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'alert_id' => 'string',
            'notification_channel_id' => 'string',
            'channel_name' => 'string',
            'event' => AlertEvent::class,
            'status' => AlertNotificationStatus::class,
            'error' => 'string',
            'created_at' => 'datetime',
            'attempted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Alert, $this>
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    /**
     * @return BelongsTo<NotificationChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }
}

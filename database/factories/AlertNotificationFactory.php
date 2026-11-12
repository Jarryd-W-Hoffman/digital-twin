<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AlertEvent;
use App\Enums\AlertNotificationStatus;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertNotification>
 */
final class AlertNotificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alert_id' => Alert::factory(),
            'notification_channel_id' => NotificationChannel::factory(),
            'channel_name' => 'Ops email',
            'event' => AlertEvent::Raised,
            'status' => AlertNotificationStatus::Queued,
            'created_at' => now(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Enums\NotificationChannelKind;
use App\Models\NotificationChannel;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationChannel>
 */
final class NotificationChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->words(2, true),
            'kind' => NotificationChannelKind::Email,
            'target' => 'ops@example.com',
            'min_severity' => AlertSeverity::Warning,
            'notify_cleared' => true,
            'notify_issues' => false,
            'timezone' => 'UTC',
        ];
    }

    /**
     * A webhook of a kind, with a signing secret for the plain kind.
     */
    public function webhook(NotificationChannelKind $kind = NotificationChannelKind::Webhook, string $url = 'https://hooks.example.com/meridian'): self
    {
        return $this->state([
            'kind' => $kind,
            'target' => $url,
            'secret' => $kind === NotificationChannelKind::Webhook ? 'whsec_test' : null,
        ]);
    }

    /**
     * Told only of alerts still unacknowledged after a number of minutes.
     */
    public function escalation(int $minutes = 15): self
    {
        return $this->state(['escalate_after_minutes' => $minutes]);
    }

    /**
     * Told about issues too.
     */
    public function issues(): self
    {
        return $this->state(['notify_issues' => true]);
    }

    /**
     * Quiet between two times of day, in a time zone.
     */
    public function quiet(string $from = '22:00', string $until = '07:00', string $timezone = 'UTC'): self
    {
        return $this->state(['quiet_from' => $from, 'quiet_until' => $until, 'timezone' => $timezone]);
    }
}

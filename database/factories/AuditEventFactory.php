<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
final class AuditEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'actor_name' => 'Test User',
            'action' => AuditAction::ProjectCreated,
            'subject' => 'Pilot sites',
            'created_at' => now(),
        ];
    }
}

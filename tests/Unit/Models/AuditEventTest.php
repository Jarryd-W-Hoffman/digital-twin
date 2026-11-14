<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\User;

it('names who did it: a person, an API token, or Meridian', function (): void {
    $person = AuditEvent::factory()->create(['actor_id' => User::factory(), 'actor_name' => 'Ana']);
    $token = AuditEvent::factory()->create(['actor_name' => null, 'actor_api' => 'Maintenance system']);
    $meridian = AuditEvent::factory()->create(['actor_name' => null]);

    expect($person->actorLabel())->toBe('Ana')
        ->and($token->actorLabel())->toBe('Maintenance system (API)')
        ->and($meridian->actorLabel())->toBe('Meridian')
        ->and($person->action)->toBe(AuditAction::ProjectCreated)
        ->and($person->organisation)->toBeInstanceOf(Organisation::class);
});

it('keeps events for the retention period, then prunes them', function (): void {
    config(['meridian.audit.retention_days' => 30]);
    $old = AuditEvent::factory()->create(['created_at' => now()->subDays(31)]);
    $recent = AuditEvent::factory()->create(['created_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => [AuditEvent::class]])->assertSuccessful();

    $this->assertModelMissing($old);
    $this->assertModelExists($recent);
});

it('keeps an event after the person who did it is deleted', function (): void {
    $user = User::factory()->create(['name' => 'Ana']);
    $event = AuditEvent::factory()->create(['actor_id' => $user->id, 'actor_name' => 'Ana']);

    $user->delete();

    expect($event->fresh())->actor_id->toBeNull()->actorLabel()->toBe('Ana');
});

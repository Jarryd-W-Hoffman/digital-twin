<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\OrganisationRole;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Twin;

it("shows admins and owners the organisation's audit log, newest first", function (OrganisationRole $role): void {
    $this->freezeSecond();
    [$user, $organisation] = member($role);
    $twin = Twin::factory()->for($organisation)->create(['name' => 'Harbour Depot']);
    $older = AuditEvent::factory()->for($organisation)->create(['created_at' => now()->subHour(), 'actor_name' => null, 'actor_api' => 'CMMS', 'action' => AuditAction::IssueRaised, 'subject' => '#1 Seal', 'project_id' => $twin->project_id, 'project_name' => 'Depots', 'twin_id' => $twin->id, 'twin_name' => 'Harbour Depot', 'ip' => '10.0.0.5']);
    $newer = AuditEvent::factory()->for($organisation)->create(['actor_id' => $user->id, 'actor_name' => 'Owner', 'action' => AuditAction::MemberAdded, 'subject' => 'ana@example.com', 'details' => ['role' => 'viewer']]);
    AuditEvent::factory()->create();

    $this->actingAs($user)->get(route('organisation.audit.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('organisation/audit')
            ->where('currentOrganisation', ['id' => $organisation->id, 'name' => $organisation->name])
            ->has('events', 2)
            ->where('events.0', [
                'id' => $newer->id,
                'at' => now()->toIso8601String(),
                'actor' => 'Owner',
                'actorId' => $user->id,
                'viaApi' => false,
                'action' => 'member.added',
                'label' => 'Added a member',
                'area' => 'access',
                'subject' => 'ana@example.com',
                'project' => null,
                'twin' => null,
                'details' => ['role' => 'viewer'],
                'ip' => null,
            ])
            ->where('events.1.id', $older->id)
            ->where('events.1.actor', 'CMMS (API)')
            ->where('events.1.viaApi', true)
            ->where('events.1.project', ['id' => $twin->project_id, 'name' => 'Depots'])
            ->where('events.1.twin', ['id' => $twin->id, 'name' => 'Harbour Depot'])
            ->where('events.1.details', [])
            ->has('areas', 5)
            ->where('areas.0', ['value' => 'access', 'label' => 'Members and API tokens'])
            ->where('area', null)
            ->where('next', null)
            ->where('previous', null)
            ->where('retentionDays', 730));
})->with([OrganisationRole::Owner, OrganisationRole::Admin]);

it('keeps the audit log from operators and viewers', function (OrganisationRole $role): void {
    [$user] = member($role);

    $this->actingAs($user)->get(route('organisation.audit.index'))->assertForbidden();
})->with([OrganisationRole::Operator, OrganisationRole::Viewer]);

it('filters by area and pages 50 at a time', function (): void {
    [$user, $organisation] = member();
    AuditEvent::factory()->for($organisation)->count(51)->create(['action' => AuditAction::ProjectCreated, 'created_at' => now()->subMinute()]);
    AuditEvent::factory()->for($organisation)->create(['action' => AuditAction::MemberRemoved]);

    $this->actingAs($user)->get(route('organisation.audit.index', ['area' => 'access']))
        ->assertInertia(fn ($page) => $page->has('events', 1)->where('events.0.action', 'member.removed')->where('area', 'access'));

    $this->get(route('organisation.audit.index', ['area' => 'projects']))
        ->assertInertia(fn ($page) => $page->has('events', 50)->where('next', fn (?string $next): bool => str_contains((string) $next, 'area=projects')));

    $this->get(route('organisation.audit.index', ['area' => 'elsewhere']))->assertSessionHasErrors('area');
});

it('records an edit made through the web with who made it', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $twin = Twin::factory()->for($organisation)->for($project)->create(['name' => 'Depot']);

    $this->actingAs($user)->patch(route('twins.update', $twin), ['name' => 'Harbour Depot', 'description' => ''])->assertRedirect();

    expect(AuditEvent::query()->sole())
        ->action->toBe(AuditAction::TwinRenamed)
        ->actor_id->toBe($user->id)
        ->twin_name->toBe('Harbour Depot')
        ->details->toBe(['was' => 'Depot'])
        ->and($twin->fresh()->description)->toBeNull();
});

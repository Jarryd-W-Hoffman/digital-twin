<?php

declare(strict_types=1);

use App\Enums\AlertEvent;
use App\Enums\AlertNotificationStatus;
use App\Enums\OrganisationRole;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\User;

it("lists a twin's alerts newest first, with who acknowledged them", function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $older = Alert::factory()->for($twin)->cleared()->create([
        'raised_at' => now()->subHours(2),
        'acknowledged_at' => now()->subHour(),
        'acknowledged_by' => $user->id,
    ]);
    AlertNotification::factory()->for($older)->create(['status' => AlertNotificationStatus::Failed, 'event' => AlertEvent::Cleared, 'error' => 'Mailbox full', 'created_at' => now()]);
    AlertNotification::factory()->for($older)->create(['status' => AlertNotificationStatus::Sent, 'created_at' => now()->subMinute()]);
    $newer = Alert::factory()->for($twin)->create(['rule' => 'tripped', 'asset' => 'plant', 'value' => 4]);
    Alert::factory()->create();

    $this->actingAs($user)->get(route('twins.alerts.index', $twin))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('twins/alerts')
            ->where('twin', ['id' => $twin->id, 'name' => $twin->name])
            ->has('alerts', 2)
            ->where('alerts.0', [
                'id' => $newer->id,
                'rule' => 'tripped',
                'asset' => 'plant',
                'severity' => 'critical',
                'message' => 'Seafood store is above its temperature limit',
                'value' => 4,
                'raisedAt' => $newer->raised_at->toIso8601String(),
                'clearedAt' => null,
                'acknowledgedAt' => null,
                'acknowledgedBy' => null,
                'notifications' => [],
            ])
            ->where('alerts.1.id', $older->id)
            ->where('alerts.1.notifications', [
                ['channel' => 'Ops email', 'event' => 'raised', 'status' => 'sent', 'error' => null],
                ['channel' => 'Ops email', 'event' => 'cleared', 'status' => 'failed', 'error' => 'Mailbox full'],
            ])
            ->where('alerts.1.clearedAt', $older->cleared_at?->toIso8601String())
            ->where('alerts.1.acknowledgedBy', $user->name)
            ->where('pages', ['previous' => null, 'next' => null])
            ->where('show', 'all')
            ->where('hasGateway', false)
            ->where('canAcknowledge', false));
});

it('shows only open alerts when asked, a page at a time', function (): void {
    [$user, $organisation] = member(OrganisationRole::Operator);
    $twin = twinIn($organisation);
    Alert::factory()->count(51)->for($twin)->sequence(fn ($sequence): array => ['raised_at' => now()->subMinutes($sequence->index)])->create();
    Alert::factory()->for($twin)->cleared()->create();

    $this->actingAs($user)->get(route('twins.alerts.index', [$twin, 'show' => 'open']))
        ->assertInertia(fn ($page) => $page
            ->has('alerts', 50)
            ->where('show', 'open')
            ->where('pages.previous', null)
            ->where('pages.next', route('twins.alerts.index', [$twin, 'show' => 'open', 'page' => 2]))
            ->where('canAcknowledge', true));

    $this->actingAs($user)->get(route('twins.alerts.index', [$twin, 'show' => 'open', 'page' => 2]))
        ->assertInertia(fn ($page) => $page->has('alerts', 1)->where('pages.next', null));
});

it('says whether the twin has a gateway', function (): void {
    [$twin] = gatewayTwin();
    $user = User::factory()->create();
    $user->memberships()->create(['organisation_id' => $twin->organisation_id, 'role' => OrganisationRole::Viewer]);

    $this->actingAs($user)->get(route('twins.alerts.index', $twin))
        ->assertInertia(fn ($page) => $page->where('hasGateway', true));
});

it("keeps outsiders out of a twin's alert log", function (): void {
    [$user] = member();
    $alert = Alert::factory()->create();

    $this->actingAs($user)->get(route('twins.alerts.index', $alert->twin))->assertForbidden();
});

it('lets operators acknowledge an alert, keeping the first to do so', function (): void {
    $this->freezeSecond();
    [$user, $organisation] = member(OrganisationRole::Operator);
    $twin = twinIn($organisation);
    $alert = Alert::factory()->for($twin)->create();

    $this->actingAs($user)->from(route('twins.alerts.index', $twin))
        ->post(route('twins.alerts.acknowledgement.store', [$twin, $alert]))
        ->assertRedirectToRoute('twins.alerts.index', $twin)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Alert acknowledged.']);

    expect($alert->refresh()->acknowledged_by)->toBe($user->id)
        ->and($alert->acknowledged_at?->equalTo(now()))->toBeTrue();

    [$other] = member(OrganisationRole::Owner);
    $other->memberships()->create(['organisation_id' => $organisation->id, 'role' => OrganisationRole::Owner]);
    $this->travel(5)->minutes();
    $this->actingAs($other)->post(route('twins.alerts.acknowledgement.store', [$twin, $alert]));

    expect($alert->refresh()->acknowledged_by)->toBe($user->id);
});

it('keeps viewers from acknowledging, and alerts to their own twin', function (): void {
    [$viewer, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $alert = Alert::factory()->for($twin)->create();

    $this->actingAs($viewer)->post(route('twins.alerts.acknowledgement.store', [$twin, $alert]))->assertForbidden();

    [$operator, $other] = member(OrganisationRole::Operator);
    $own = twinIn($other);
    $this->actingAs($operator)->post(route('twins.alerts.acknowledgement.store', [$own, $alert]))->assertNotFound();

    expect($alert->refresh()->acknowledged_at)->toBeNull();
});

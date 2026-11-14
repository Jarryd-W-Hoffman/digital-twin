<?php

declare(strict_types=1);

use App\Actions\AcknowledgeAlert;
use App\Actions\AddMember;
use App\Actions\AllowOpcUaWrites;
use App\Actions\AssignIssue;
use App\Actions\CloseIssue;
use App\Actions\CreateApiToken;
use App\Actions\CreateDevice;
use App\Actions\CreateIssue;
use App\Actions\CreateNotificationChannel;
use App\Actions\CreateOpcUaServer;
use App\Actions\CreateProject;
use App\Actions\CreateTwin;
use App\Actions\DeleteAssetType;
use App\Actions\DeleteDevice;
use App\Actions\DeleteIssue;
use App\Actions\DeleteNotificationChannel;
use App\Actions\DeleteOpcUaServer;
use App\Actions\DeleteProject;
use App\Actions\DeleteProjectAsset;
use App\Actions\DeleteTwin;
use App\Actions\IssueDevicePassword;
use App\Actions\IssueGatewayToken;
use App\Actions\RecordAuditEvent;
use App\Actions\RemoveMember;
use App\Actions\ReopenIssue;
use App\Actions\RevokeApiToken;
use App\Actions\RevokeGatewayToken;
use App\Actions\SaveTwinVersion;
use App\Actions\SaveTypeToLibrary;
use App\Actions\SetOpeningView;
use App\Actions\StoreProjectAsset;
use App\Actions\UpdateMemberRole;
use App\Actions\UpdateTwin;
use App\Enums\AlertSeverity;
use App\Enums\ApiTokenAccess;
use App\Enums\AuditAction;
use App\Enums\NotificationChannelKind;
use App\Enums\OrganisationRole;
use App\Models\Alert;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\SavedView;
use App\Models\User;
use App\Support\SampleTwins;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;

it('records who did what, where, from which address', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $this->actingAs($user);

    $event = resolve(RecordAuditEvent::class)->handle(AuditAction::GatewayTokenIssued, $twin, 'Harbour Depot', ['note' => 'x']);

    expect($event->fresh())
        ->organisation_id->toBe($organisation->id)
        ->actor_id->toBe($user->id)
        ->actor_name->toBe($user->name)
        ->actor_api->toBeNull()
        ->action->toBe(AuditAction::GatewayTokenIssued)
        ->subject->toBe('Harbour Depot')
        ->project_id->toBe($twin->project_id)
        ->project_name->toBe($twin->project->name)
        ->twin_id->toBe($twin->id)
        ->twin_name->toBe($twin->name)
        ->details->toBe(['note' => 'x'])
        ->ip->toBe('127.0.0.1');
});

it('records an API token by name, and Meridian when no one is signed in', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $this->actingAs($user);

    Context::addHidden(RecordAuditEvent::API_TOKEN, 'Maintenance system');
    $byToken = resolve(RecordAuditEvent::class)->handle(AuditAction::IssueRaised, $project);
    Context::forgetHidden(RecordAuditEvent::API_TOKEN);
    auth()->logout();
    $byMeridian = resolve(RecordAuditEvent::class)->handle(AuditAction::ProjectCreated, $organisation);

    expect($byToken->fresh())->actor_id->toBeNull()->actor_api->toBe('Maintenance system')->project_id->toBe($project->id)->twin_id->toBeNull()->details->toBeNull()
        ->and($byMeridian->fresh())->actorLabel()->toBe('Meridian')->project_id->toBeNull()->ip->toBeNull();
});

it('records what people do across the organisation', function (): void {
    Storage::fake('assets');
    config(['meridian.assets_disk' => 'assets']);
    [$user, $organisation] = member();
    $this->actingAs($user);
    $ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);

    $membership = resolve(AddMember::class)->handle($organisation, 'ana@example.com', OrganisationRole::Viewer);
    resolve(UpdateMemberRole::class)->handle($membership, OrganisationRole::Operator);
    resolve(RemoveMember::class)->handle($membership->fresh());
    [$token] = resolve(CreateApiToken::class)->handle($organisation, $user, 'CMMS', ApiTokenAccess::Read, null);
    resolve(RevokeApiToken::class)->handle($token);

    $project = resolve(CreateProject::class)->handle($organisation, 'Depots');
    $asset = resolve(StoreProjectAsset::class)->handle($project, glbUpload(), 'Pump');
    resolve(DeleteProjectAsset::class)->handle($asset, $user);
    $device = resolve(CreateDevice::class)->handle($project, 'sensor-1');
    resolve(IssueDevicePassword::class)->handle($device);
    resolve(DeleteDevice::class)->handle($device);

    $server = resolve(CreateOpcUaServer::class)->handle($project, 'PLC', 'opc.tcp://plc.local:4840');
    resolve(DeleteOpcUaServer::class)->handle($server);
    $channel = resolve(CreateNotificationChannel::class)->handle($project, [
        'name' => 'Ops', 'kind' => NotificationChannelKind::Email, 'target' => 'ops@example.com', 'min_severity' => AlertSeverity::Warning,
        'notify_cleared' => false, 'notify_issues' => false, 'report' => null, 'escalate_after_minutes' => null,
        'quiet_from' => null, 'quiet_until' => null, 'timezone' => 'UTC',
    ]);
    resolve(DeleteNotificationChannel::class)->handle($channel);
    $type = resolve(SaveTypeToLibrary::class)->handle($organisation, $user, northshoreType('water-tank'));
    resolve(DeleteAssetType::class)->handle($type);

    $twin = resolve(CreateTwin::class)->handle($project, $user, 'Depot', null, resolve(SampleTwins::class)->definition('harbour-depot'));
    resolve(UpdateTwin::class)->handle($twin, 'Harbour Depot', 'Cold store');
    resolve(UpdateTwin::class)->handle($twin, 'Harbour Depot', null);
    resolve(SaveTwinVersion::class)->handle($twin, $user, resolve(SampleTwins::class)->definition('harbour-depot'), 'Moved the pump');
    resolve(SetOpeningView::class)->handle($twin, SavedView::factory()->for($twin)->create(['name' => 'Yard']));
    resolve(SetOpeningView::class)->handle($twin, null);
    resolve(IssueGatewayToken::class)->handle($twin);
    resolve(RevokeGatewayToken::class)->handle($twin);
    resolve(AllowOpcUaWrites::class)->handle($twin, true);

    $alert = Alert::factory()->for($twin)->create();
    resolve(AcknowledgeAlert::class)->handle($alert, $user);
    resolve(AcknowledgeAlert::class)->handle($alert, $user);

    $at = ['lon' => 174.76, 'lat' => -36.85, 'height' => 10.0];
    $issue = resolve(CreateIssue::class)->handle($twin, $user, 'Seal torn', $at, [...$at, 'heading' => 0.0, 'pitch' => -35.0, 'roll' => 0.0]);
    resolve(AssignIssue::class)->handle($issue, $user, $ana, now()->addDay());
    resolve(CloseIssue::class)->handle($issue, $user);
    resolve(CloseIssue::class)->handle($issue, $user);
    resolve(ReopenIssue::class)->handle($issue->fresh());
    resolve(DeleteIssue::class)->handle($issue->fresh());
    resolve(DeleteTwin::class)->handle($twin);
    resolve(DeleteProject::class)->handle($project);

    $events = AuditEvent::query()->oldest()->oldest('id')->get();

    expect($events->map(fn (AuditEvent $event): string => $event->action->value.' '.$event->subject)->all())->toBe([
        'member.added ana@example.com',
        'member.role_changed ana@example.com',
        'member.removed ana@example.com',
        'api_token.created CMMS',
        'api_token.revoked CMMS',
        'project.created Depots',
        'upload.added Pump',
        'upload.deleted Pump',
        'device.added sensor-1',
        'device.password_reset sensor-1',
        'device.deleted sensor-1',
        'opcua_server.added PLC',
        'opcua_server.deleted PLC',
        'channel.added Ops',
        'channel.deleted Ops',
        'type.saved Fire water tank',
        'type.deleted Fire water tank',
        'twin.created Depot',
        'twin.renamed Harbour Depot',
        'twin.renamed Harbour Depot',
        'twin.version_saved Version 2',
        'twin.opening_view_changed Yard',
        'twin.opening_view_changed The whole site',
        'gateway_token.issued Harbour Depot',
        'gateway_token.revoked Harbour Depot',
        'twin.opcua_writes_changed On',
        'alert.acknowledged Seafood store is above its temperature limit',
        'issue.raised #1 Seal torn',
        'issue.assigned #1 Seal torn',
        'issue.closed #1 Seal torn',
        'issue.reopened #1 Seal torn',
        'issue.deleted #1 Seal torn',
        'twin.deleted Harbour Depot',
        'project.deleted Depots',
    ])
        ->and($events->every(fn (AuditEvent $event): bool => $event->actor_id === $user->id && $event->organisation_id === $organisation->id))->toBeTrue()
        ->and($events[1]->details)->toBe(['from' => 'viewer', 'to' => 'operator'])
        ->and($events[18]->details)->toBe(['was' => 'Depot'])
        ->and($events[19]->details)->toBeNull()
        ->and($events[18]->twin_name)->toBe('Harbour Depot')
        ->and($events[28]->details)->toBe(['assignee' => 'Ana', 'dueOn' => now()->addDay()->format('Y-m-d')])
        ->and($events[33]->project_name)->toBe('Depots');
});

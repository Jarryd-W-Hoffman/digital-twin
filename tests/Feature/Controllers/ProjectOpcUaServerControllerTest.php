<?php

declare(strict_types=1);

use App\Enums\OpcUaSecurityMode;
use App\Enums\OpcUaSecurityPolicy;
use App\Enums\OrganisationRole;
use App\Models\OpcUaServer;
use App\Models\Project;

/**
 * @return array<string, string>
 */
function opcUaServerForm(array $overrides = []): array
{
    return [
        'name' => 'Plant PLC',
        'endpoint' => 'opc.tcp://plc.local:4840',
        'security_mode' => 'none',
        'security_policy' => 'None',
        'username' => '',
        'password' => '',
        ...$overrides,
    ];
}

it('adds an anonymous, unsecured server', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.opcua-servers.store', $project), opcUaServerForm())
        ->assertRedirectToRoute('projects.show', $project)
        ->assertInertiaFlash('toast.message', 'Plant PLC added.');

    $server = $project->opcUaServers()->sole();
    expect($server->name)->toBe('Plant PLC')
        ->and($server->endpoint)->toBe('opc.tcp://plc.local:4840')
        ->and($server->security_mode)->toBe(OpcUaSecurityMode::None)
        ->and($server->security_policy)->toBe(OpcUaSecurityPolicy::None)
        ->and($server->username)->toBeNull()
        ->and($server->password)->toBeNull();
});

it('adds a secured server with a login, keeping the password encrypted', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.opcua-servers.store', $project), opcUaServerForm([
        'security_mode' => 'signAndEncrypt',
        'security_policy' => 'Aes256_Sha256_RsaPss',
        'username' => ' meridian ',
        'password' => 'plc-secret',
    ]))->assertSessionHasNoErrors();

    $server = $project->opcUaServers()->sole();
    expect($server->security_policy)->toBe(OpcUaSecurityPolicy::Aes256Sha256RsaPss)
        ->and($server->username)->toBe('meridian')
        ->and($server->password)->toBe('plc-secret')
        ->and($server->getRawOriginal('password'))->not->toContain('plc-secret');
});

it('refuses bad endpoints, mismatched security and duplicate names', function (array $overrides, string $field): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $project = Project::factory()->for($organisation)->create();
    OpcUaServer::factory()->for($project)->create(['name' => 'Taken']);

    $this->actingAs($user)->post(route('projects.opcua-servers.store', $project), opcUaServerForm($overrides))
        ->assertSessionHasErrors($field);

    expect($project->opcUaServers()->count())->toBe(1);
})->with([
    'an http endpoint' => [['endpoint' => 'http://plc.local:4840'], 'endpoint'],
    'a name in use' => [['name' => 'Taken'], 'name'],
    'an unknown mode' => [['security_mode' => 'maybe'], 'security_mode'],
    'a policy with no security' => [['security_policy' => 'Basic256Sha256'], 'security_policy'],
    'security with no policy' => [['security_mode' => 'sign'], 'security_policy'],
    'a username without a password' => [['username' => 'meridian'], 'password'],
]);

it('allows the same name in another project', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $project = Project::factory()->for($organisation)->create();
    OpcUaServer::factory()->create(['name' => 'Plant PLC']);

    $this->actingAs($user)->post(route('projects.opcua-servers.store', $project), opcUaServerForm())
        ->assertSessionHasNoErrors();
});

it('deletes a server', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $server = OpcUaServer::factory()->for(Project::factory()->for($organisation))->create();

    $this->actingAs($user)->delete(route('projects.opcua-servers.destroy', [$server->project, $server]))
        ->assertRedirectToRoute('projects.show', $server->project);

    $this->assertModelMissing($server);
});

it("lets only editors manage servers, and only their own project's", function (): void {
    [$viewer, $organisation] = member(OrganisationRole::Viewer);
    $server = OpcUaServer::factory()->for(Project::factory()->for($organisation))->create();
    $other = OpcUaServer::factory()->create();

    $this->actingAs($viewer)->post(route('projects.opcua-servers.store', $server->project), opcUaServerForm())->assertForbidden();
    $this->actingAs($viewer)->delete(route('projects.opcua-servers.destroy', [$server->project, $server]))->assertForbidden();
    $this->actingAs($viewer)->delete(route('projects.opcua-servers.destroy', [$server->project, $other]))->assertNotFound();

    $this->assertModelExists($server);
});

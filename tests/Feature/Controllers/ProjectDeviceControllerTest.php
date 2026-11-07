<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use App\Models\Device;
use App\Models\Project;
use Inertia\Support\SessionKey;

it('adds a device and shows its broker login once', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $project = Project::factory()->for($organisation)->create();

    $response = $this->actingAs($user)->post(route('projects.devices.store', $project), ['name' => 'Freezer probe 1'])
        ->assertRedirectToRoute('projects.show', $project);

    $device = $project->devices()->sole();
    $response->assertInertiaFlash('deviceCredentials.deviceId', $device->id)
        ->assertInertiaFlash('deviceCredentials.username', $device->mqttUsername());
    $password = session(SessionKey::FLASH_DATA)['deviceCredentials']['password'];

    expect($device->name)->toBe('Freezer probe 1')
        ->and($password)->toStartWith('mdv_')
        ->and(hash('sha256', $password))->toBe($device->password_hash);
});

it('keeps device names unique within a project', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $project = Project::factory()->for($organisation)->create();
    Device::factory()->for($project)->create(['name' => 'Probe']);
    Device::factory()->create(['name' => 'Elsewhere']);

    $this->actingAs($user)->post(route('projects.devices.store', $project), ['name' => 'Probe'])->assertSessionHasErrors('name');
    $this->actingAs($user)->post(route('projects.devices.store', $project), ['name' => 'Elsewhere'])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('projects.devices.store', $project), ['name' => ''])->assertSessionHasErrors('name');
});

it('resets a device password, shutting out the old one', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $device = Device::factory()->for(Project::factory()->for($organisation))->create(['last_seen_at' => now()]);
    $before = $device->password_hash;

    $response = $this->actingAs($user)->post(route('projects.devices.password.store', [$device->project, $device]))
        ->assertRedirectToRoute('projects.show', $device->project)
        ->assertInertiaFlash('deviceCredentials.deviceId', $device->id);
    $password = session(SessionKey::FLASH_DATA)['deviceCredentials']['password'];

    expect($device->refresh()->password_hash)->not->toBe($before)
        ->and(hash('sha256', $password))->toBe($device->password_hash)
        ->and($device->last_seen_at)->toBeNull();
});

it('deletes a device', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $device = Device::factory()->for(Project::factory()->for($organisation))->create();

    $this->actingAs($user)->delete(route('projects.devices.destroy', [$device->project, $device]))
        ->assertRedirectToRoute('projects.show', $device->project);

    $this->assertModelMissing($device);
});

it("lets only editors manage devices, and only their own project's", function (): void {
    [$viewer, $organisation] = member(OrganisationRole::Viewer);
    $device = Device::factory()->for(Project::factory()->for($organisation))->create();
    $other = Device::factory()->create();

    $this->actingAs($viewer)->post(route('projects.devices.store', $device->project), ['name' => 'Mine'])->assertForbidden();
    $this->actingAs($viewer)->post(route('projects.devices.password.store', [$device->project, $device]))->assertForbidden();
    $this->actingAs($viewer)->delete(route('projects.devices.destroy', [$device->project, $device]))->assertForbidden();
    $this->actingAs($viewer)->delete(route('projects.devices.destroy', [$device->project, $other]))->assertNotFound();

    $this->assertModelExists($device);
});

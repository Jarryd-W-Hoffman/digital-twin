<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\OrganisationRole;
use App\Models\AuditEvent;
use App\Models\ModbusDevice;
use App\Models\Project;

/**
 * @return array<string, int|string>
 */
function modbusDeviceForm(array $overrides = []): array
{
    return [
        'name' => 'Main meter',
        'host' => '192.168.1.20',
        'port' => 502,
        'unit_id' => 1,
        ...$overrides,
    ];
}

it('adds a device, and records it in the audit log', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.modbus-devices.store', $project), modbusDeviceForm(['host' => 'meter.local', 'port' => 1502, 'unit_id' => 7]))
        ->assertRedirectToRoute('projects.show', $project)
        ->assertInertiaFlash('toast.message', 'Main meter added.');

    $device = $project->modbusDevices()->sole();
    expect($device->name)->toBe('Main meter')
        ->and($device->host)->toBe('meter.local')
        ->and($device->port)->toBe(1502)
        ->and($device->unit_id)->toBe(7)
        ->and($device->forGateway())->toBe(['name' => 'Main meter', 'host' => 'meter.local', 'port' => 1502, 'unitId' => 7]);

    $event = AuditEvent::query()->sole();
    expect($event->action)->toBe(AuditAction::ModbusDeviceAdded)
        ->and($event->details)->toBe(['address' => 'meter.local:1502', 'unit' => 7]);
});

it('refuses bad hosts, ports, unit ids and duplicate names', function (array $overrides, string $field): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $project = Project::factory()->for($organisation)->create();
    ModbusDevice::factory()->for($project)->create(['name' => 'Taken']);

    $this->actingAs($user)->post(route('projects.modbus-devices.store', $project), modbusDeviceForm($overrides))
        ->assertSessionHasErrors($field);

    expect($project->modbusDevices()->count())->toBe(1);
})->with([
    'a host with a port' => [['host' => 'meter.local:502'], 'host'],
    'a URL' => [['host' => 'tcp://meter.local'], 'host'],
    'no host' => [['host' => ''], 'host'],
    'port 0' => [['port' => 0], 'port'],
    'a port too high' => [['port' => 70000], 'port'],
    'a unit id too high' => [['unit_id' => 256], 'unit_id'],
    'a name in use' => [['name' => 'Taken'], 'name'],
]);

it('says how to write a host', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.modbus-devices.store', $project), modbusDeviceForm(['host' => 'meter.local:502']))
        ->assertSessionHasErrors(['host' => 'The host must be a name or an IP address, such as meter.local or 192.168.1.20, without a port.']);
});

it('allows the same name in another project, and IPv6 hosts', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $project = Project::factory()->for($organisation)->create();
    ModbusDevice::factory()->create(['name' => 'Main meter']);

    $this->actingAs($user)->post(route('projects.modbus-devices.store', $project), modbusDeviceForm(['host' => 'fd00::20']))
        ->assertSessionHasNoErrors();
});

it('deletes a device, and records it in the audit log', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $device = ModbusDevice::factory()->for(Project::factory()->for($organisation))->create();

    $this->actingAs($user)->delete(route('projects.modbus-devices.destroy', [$device->project, $device]))
        ->assertRedirectToRoute('projects.show', $device->project)
        ->assertInertiaFlash('toast.message', 'Main meter deleted.');

    $this->assertModelMissing($device);
    expect(AuditEvent::query()->sole()->action)->toBe(AuditAction::ModbusDeviceDeleted);
});

it("lets only editors manage devices, and only their own project's", function (): void {
    [$viewer, $organisation] = member(OrganisationRole::Viewer);
    $device = ModbusDevice::factory()->for(Project::factory()->for($organisation))->create();
    $other = ModbusDevice::factory()->create();

    $this->actingAs($viewer)->post(route('projects.modbus-devices.store', $device->project), modbusDeviceForm(['name' => 'New']))->assertForbidden();
    $this->actingAs($viewer)->delete(route('projects.modbus-devices.destroy', [$device->project, $device]))->assertForbidden();
    $this->actingAs($viewer)->delete(route('projects.modbus-devices.destroy', [$device->project, $other]))->assertNotFound();

    $this->assertModelExists($device);
});

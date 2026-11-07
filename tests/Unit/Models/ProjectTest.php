<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Project;
use Illuminate\Support\Str;

test('to array', function (): void {
    expect(array_keys(Project::factory()->create()->refresh()->toArray()))
        ->toBe(['id', 'organisation_id', 'name', 'description', 'created_at', 'updated_at', 'mqtt_key']);
});

it('belongs to an organisation and stores its uploads under its own prefix', function (): void {
    [, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    expect($project->organisation->is($organisation))->toBeTrue()
        ->and($organisation->projects->sole()->is($project))->toBeTrue()
        ->and($project->storagePrefix())->toBe('projects/'.$project->id);
});

it('keeps a readable topic key from its first name, unique and unchanged by renaming', function (): void {
    $project = Project::factory()->create(['name' => 'Northshore Cold Store & Microgrid']);
    $key = $project->mqtt_key;

    $project->update(['name' => 'Renamed']);

    expect($key)->toMatch('/^northshore-cold-stor-[a-z0-9]{4}$/')
        ->and($project->refresh()->mqtt_key)->toBe($key)
        ->and($project->topicPrefix())->toBe('p/'.$key.'/')
        ->and(Project::newMqttKey('!!!'))->toMatch('/^project-[a-z0-9]{4}$/')
        ->and(Project::factory()->create(['mqtt_key' => 'given-key'])->mqtt_key)->toBe('given-key');
});

it('draws a new suffix when a key is taken', function (): void {
    Str::createRandomStringsUsingSequence(['ABCD', 'ABCD', 'EFGH']);
    Project::factory()->create(['name' => 'Site']);

    expect(Project::newMqttKey('Site'))->toBe('site-efgh');
    Str::createRandomStringsNormally();
});

it('has devices', function (): void {
    $device = Device::factory()->create();

    expect($device->project->devices->sole()->is($device))->toBeTrue();
});

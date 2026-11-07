<?php

declare(strict_types=1);

use App\Actions\AuthorizeMqttTopic;
use App\Models\Device;
use App\Models\Project;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => config(['meridian.mqtt.auth_secret' => 'broker-secret']));

/**
 * @param  array<string, mixed>  $body
 */
function askBroker(string $check, array $body, string $secret = 'broker-secret'): TestResponse
{
    return test()->postJson('/api/mqtt/'.$secret.'/'.$check, $body);
}

it('lets a device in with its password, and notes when it was seen', function (): void {
    $this->freezeSecond();
    $device = Device::factory()->create(['password_hash' => hash('sha256', 'mdv_right')]);

    askBroker('user', ['username' => $device->mqttUsername(), 'password' => 'mdv_wrong', 'clientid' => 'probe'])->assertForbidden();
    expect($device->refresh()->last_seen_at)->toBeNull();

    askBroker('user', ['username' => $device->mqttUsername(), 'password' => 'mdv_right', 'clientid' => 'probe'])->assertOk();
    expect($device->refresh()->last_seen_at?->equalTo(now()))->toBeTrue();
});

it("lets a gateway in with its twin's token", function (): void {
    [$twin, $token] = gatewayTwin();

    askBroker('user', ['username' => $twin->mqttUsername(), 'password' => $token])->assertOk();
    askBroker('user', ['username' => $twin->mqttUsername(), 'password' => 'mgw_wrong'])->assertForbidden();

    $twin->update(['gateway_token_hash' => null]);
    askBroker('user', ['username' => $twin->mqttUsername(), 'password' => $token])->assertForbidden();
});

it('turns away unknown and malformed logins', function (array $body): void {
    askBroker('user', $body)->assertStatus(in_array('', $body, true) || count($body) < 2 ? 422 : 403);
})->with([
    'unknown device' => [['username' => 'device-'.Str::uuid(), 'password' => 'x']],
    'unknown twin' => [['username' => 'twin-'.Str::uuid(), 'password' => 'x']],
    'not a uuid' => [['username' => 'device-1 or 1=1', 'password' => 'x']],
    'someone else' => [['username' => 'admin', 'password' => 'x']],
    'no password' => [['username' => 'admin']],
]);

it('lets devices publish only below their project prefix', function (): void {
    $device = Device::factory()->create();
    $prefix = $device->project->topicPrefix();
    $other = Project::factory()->create()->topicPrefix();
    $user = $device->mqttUsername();

    askBroker('acl', ['username' => $user, 'topic' => $prefix.'freezer/temp', 'acc' => AuthorizeMqttTopic::WRITE])->assertOk();
    askBroker('acl', ['username' => $user, 'topic' => $other.'freezer/temp', 'acc' => AuthorizeMqttTopic::WRITE])->assertForbidden();
    askBroker('acl', ['username' => $user, 'topic' => $prefix, 'acc' => AuthorizeMqttTopic::WRITE])->assertForbidden();
    askBroker('acl', ['username' => $user, 'topic' => 'p/'.$device->project->mqtt_key, 'acc' => AuthorizeMqttTopic::WRITE])->assertForbidden();
    askBroker('acl', ['username' => $user, 'topic' => $prefix.'#', 'acc' => AuthorizeMqttTopic::SUBSCRIBE])->assertForbidden();
    askBroker('acl', ['username' => $user, 'topic' => $prefix.'freezer/temp', 'acc' => AuthorizeMqttTopic::READ])->assertForbidden();
});

it("lets gateways subscribe only below their twin's project prefix", function (): void {
    [$twin] = gatewayTwin();
    $prefix = $twin->project->topicPrefix();
    $user = $twin->mqttUsername();

    askBroker('acl', ['username' => $user, 'topic' => $prefix.'#', 'acc' => AuthorizeMqttTopic::SUBSCRIBE])->assertOk();
    askBroker('acl', ['username' => $user, 'topic' => $prefix.'freezer/temp', 'acc' => AuthorizeMqttTopic::READ])->assertOk();
    askBroker('acl', ['username' => $user, 'topic' => $prefix.'freezer/temp', 'acc' => AuthorizeMqttTopic::WRITE])->assertForbidden();
    askBroker('acl', ['username' => $user, 'topic' => '#', 'acc' => AuthorizeMqttTopic::SUBSCRIBE])->assertForbidden();
    askBroker('acl', ['username' => $user, 'topic' => 'p/+/#', 'acc' => AuthorizeMqttTopic::SUBSCRIBE])->assertForbidden();
    askBroker('acl', ['username' => $user, 'topic' => '$SYS/#', 'acc' => AuthorizeMqttTopic::SUBSCRIBE])->assertForbidden();

    $twin->update(['gateway_token_hash' => null]);
    askBroker('acl', ['username' => $user, 'topic' => $prefix.'#', 'acc' => AuthorizeMqttTopic::SUBSCRIBE])->assertForbidden();
});

it('denies topics to unknown clients', function (): void {
    askBroker('acl', ['username' => 'admin', 'topic' => 'p/x/y', 'acc' => 2])->assertForbidden();
    askBroker('acl', ['username' => 'device-'.Str::uuid(), 'topic' => 'p/x/y', 'acc' => 2])->assertForbidden();
    askBroker('acl', ['username' => 'admin', 'topic' => 'p/x/y'])->assertUnprocessable();
});

it('only answers the broker, which knows the secret', function (): void {
    $device = Device::factory()->create(['password_hash' => hash('sha256', 'mdv_right')]);
    $login = ['username' => $device->mqttUsername(), 'password' => 'mdv_right'];

    askBroker('user', $login, 'guess')->assertNotFound();

    config(['meridian.mqtt.auth_secret' => null]);
    askBroker('user', $login, 'broker-secret')->assertNotFound();
    askBroker('acl', ['username' => $device->mqttUsername(), 'topic' => 'p/x/y', 'acc' => 2], '')->assertNotFound();
});

it('limits login attempts per username', function (): void {
    $device = Device::factory()->create(['password_hash' => hash('sha256', 'mdv_right')]);

    foreach (range(1, 30) as $_) {
        askBroker('user', ['username' => $device->mqttUsername(), 'password' => 'guess'])->assertForbidden();
    }

    askBroker('user', ['username' => $device->mqttUsername(), 'password' => 'mdv_right'])->assertTooManyRequests();
    askBroker('user', ['username' => 'device-'.Str::uuid(), 'password' => 'guess'])->assertForbidden();
});

<?php

declare(strict_types=1);

use App\Jobs\DeliverIssueNotification;
use App\Models\Alert;
use App\Models\ApiToken;
use App\Models\Issue;
use App\Models\NotificationChannel;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\Reading;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const WRITE_TOKEN = 'mrd_maintenance-system-token-that-may-write-abcd';

/**
 * A twin in an organisation with a token that may write, named CMMS.
 *
 * @return array{Twin, ApiToken}
 */
function writableTwin(): array
{
    $organisation = Organisation::factory()->create();
    $token = ApiToken::factory()->for($organisation)->writes()->token(WRITE_TOKEN)->create(['name' => 'CMMS']);

    return [twinIn($organisation), $token];
}

it('lets only tokens that may write raise, comment, close and acknowledge', function (): void {
    $organisation = Organisation::factory()->create();
    ApiToken::factory()->for($organisation)->token(WRITE_TOKEN)->create();
    $twin = twinIn($organisation);
    $issue = Issue::factory()->for($twin)->create();
    $alert = Alert::factory()->for($twin)->create();

    foreach ([
        ['post', route('api.v1.twins.issues.store', $twin)],
        ['post', route('api.v1.twins.issues.comments.store', [$twin, $issue])],
        ['post', route('api.v1.twins.issues.closure.store', [$twin, $issue])],
        ['delete', route('api.v1.twins.issues.closure.destroy', [$twin, $issue])],
        ['post', route('api.v1.twins.alerts.acknowledgement.store', [$twin, $alert])],
    ] as [$method, $url]) {
        $this->withToken(WRITE_TOKEN)->json($method, $url, ['title' => 'Leak', 'body' => 'Wet'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This API token may only read.');
    }

    expect($twin->issues()->count())->toBe(1)
        ->and($issue->comments()->count())->toBe(0)
        ->and($issue->refresh()->closed_at)->toBeNull()
        ->and($alert->refresh()->acknowledged_at)->toBeNull();
});

it('raises an issue signed with the token, looking at the pin unless given a camera, and tells channels', function (): void {
    $this->freezeSecond();
    Queue::fake();
    Storage::fake('local');
    [$twin, $token] = writableTwin();
    $channel = NotificationChannel::factory()->for($twin->project)->issues()->create();
    $alert = Alert::factory()->for($twin)->create();

    $response = $this->withToken(WRITE_TOKEN)->postJson(route('api.v1.twins.issues.store', $twin), [
        'title' => 'Work order 4471: check the seafood store door seal',
        'at' => ['lon' => 174.5, 'lat' => -36.5, 'height' => 10],
        'body' => 'Raised from the CMMS',
        'alert' => $alert->id,
    ])->assertCreated()
        ->assertJsonPath('data.number', 1)
        ->assertJsonPath('data.author', 'CMMS (API)')
        ->assertJsonPath('data.alertId', $alert->id)
        ->assertJsonPath('data.at', ['lon' => 174.5, 'lat' => -36.5, 'height' => 10]);

    $issue = Issue::query()->findOrFail($response->json('data.id'));
    expect($issue->author_id)->toBeNull()
        ->and($issue->author_api)->toBe('CMMS')
        ->and($issue->view['lon'])->toBe(174.5)
        ->and($issue->view['lat'])->toEqualWithDelta(-36.5 - 80 / 111_320, 1e-9)
        ->and($issue->view['height'])->toEqual(70)
        ->and($issue->view['pitch'])->toEqual(-35)
        ->and($issue->comments()->sole()->authorName())->toBe('CMMS (API)');
    Queue::assertPushed(fn (DeliverIssueNotification $job): bool => $job->channel->is($channel) && $job->message->by === 'CMMS (API)');

    $this->withToken(WRITE_TOKEN)->post(route('api.v1.twins.issues.store', $twin), [
        'title' => 'Photo of the leak',
        'at' => ['lon' => 174.5, 'lat' => -36.5, 'height' => 10],
        'view' => ['lon' => 174.4, 'lat' => -36.6, 'height' => 90, 'heading' => 45, 'pitch' => -20, 'roll' => 0],
        'photo' => UploadedFile::fake()->image('leak.png'),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.number', 2);
    expect(Issue::query()->where('number', 2)->sole()->view)->toEqual(['lon' => 174.4, 'lat' => -36.6, 'height' => 90, 'heading' => 45, 'pitch' => -20, 'roll' => 0]);

    $token->delete();
    expect($issue->refresh()->authorName())->toBe('CMMS (API)');
});

it('checks a new issue', function (): void {
    [$twin] = writableTwin();
    $theirs = Alert::factory()->create();

    $this->withToken(WRITE_TOKEN)->postJson(route('api.v1.twins.issues.store', $twin), [
        'at' => ['lon' => 200],
        'view' => ['lon' => 0],
        'alert' => $theirs->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['title', 'at.lon', 'at.lat', 'at.height', 'view.lat', 'view.pitch', 'alert']);
});

it('comments on an issue, with words or a photo', function (): void {
    $this->freezeSecond();
    Storage::fake('local');
    [$twin] = writableTwin();
    $issue = Issue::factory()->for($twin)->create();

    $response = $this->withToken(WRITE_TOKEN)->postJson(route('api.v1.twins.issues.comments.store', [$twin, $issue]), ['body' => 'Technician booked for Tuesday'])
        ->assertCreated()
        ->assertJsonPath('data.author', 'CMMS (API)')
        ->assertJsonPath('data.body', 'Technician booked for Tuesday')
        ->assertJsonPath('data.photo', null)
        ->assertJsonPath('data.createdAt', now()->toIso8601String());
    expect($issue->comments()->sole()->id)->toBe($response->json('data.id'));

    $photo = $this->withToken(WRITE_TOKEN)->post(route('api.v1.twins.issues.comments.store', [$twin, $issue]), ['photo' => UploadedFile::fake()->image('seal.jpg')], ['Accept' => 'application/json'])
        ->assertCreated();
    $this->withToken(WRITE_TOKEN)->get((string) $photo->json('data.photo'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    $this->withToken(WRITE_TOKEN)->postJson(route('api.v1.twins.issues.comments.store', [$twin, $issue]), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body' => 'Write something or add a photo.']);
});

it('closes and reopens an issue, keeping whoever closed it first', function (): void {
    $this->freezeSecond();
    [$twin] = writableTwin();
    $issue = Issue::factory()->for($twin)->create();

    $this->withToken(WRITE_TOKEN)->postJson(route('api.v1.twins.issues.closure.store', [$twin, $issue]))
        ->assertOk()
        ->assertJsonPath('data.closedAt', now()->toIso8601String())
        ->assertJsonPath('data.closedBy', 'CMMS (API)');

    $this->withToken(WRITE_TOKEN)->deleteJson(route('api.v1.twins.issues.closure.destroy', [$twin, $issue]))
        ->assertOk()
        ->assertJsonPath('data.closedAt', null)
        ->assertJsonPath('data.closedBy', null);
    expect($issue->refresh()->closed_by_api)->toBeNull();

    $ana = User::factory()->create(['name' => 'Ana']);
    $issue->update(['closed_at' => now(), 'closed_by' => $ana->id]);
    $this->withToken(WRITE_TOKEN)->postJson(route('api.v1.twins.issues.closure.store', [$twin, $issue]))->assertJsonPath('data.closedBy', 'Ana');
});

it('acknowledges an alert, keeping the first acknowledgement', function (): void {
    $this->freezeSecond();
    [$twin] = writableTwin();
    $alert = Alert::factory()->for($twin)->create();
    $seen = Alert::factory()->for($twin)->create(['acknowledged_at' => now()->subHour(), 'acknowledged_by' => User::factory()->create(['name' => 'Ana'])->id]);

    $this->withToken(WRITE_TOKEN)->postJson(route('api.v1.twins.alerts.acknowledgement.store', [$twin, $alert]))
        ->assertOk()
        ->assertJsonPath('data.acknowledgedAt', now()->toIso8601String())
        ->assertJsonPath('data.acknowledgedBy', 'CMMS (API)');
    $this->withToken(WRITE_TOKEN)->postJson(route('api.v1.twins.alerts.acknowledgement.store', [$twin, $seen]))
        ->assertOk()
        ->assertJsonPath('data.acknowledgedBy', 'Ana');
});

it("writes nothing in another organisation's twins, or through the wrong twin", function (): void {
    [$twin] = writableTwin();
    $theirs = Twin::factory()->inProject(Project::factory()->create())->create();
    $their = Issue::factory()->for($theirs)->create();
    $theirAlert = Alert::factory()->for($theirs)->create();
    $issue = Issue::factory()->for($twin)->create();

    foreach ([
        ['post', route('api.v1.twins.issues.store', $theirs)],
        ['post', route('api.v1.twins.issues.comments.store', [$theirs, $their])],
        ['post', route('api.v1.twins.issues.closure.store', [$theirs, $their])],
        ['delete', route('api.v1.twins.issues.closure.destroy', [$theirs, $their])],
        ['post', route('api.v1.twins.alerts.acknowledgement.store', [$theirs, $theirAlert])],
        ['post', route('api.v1.twins.alerts.acknowledgement.store', [$twin, $theirAlert])],
        ['post', route('api.v1.twins.issues.closure.store', [$theirs, $issue])],
    ] as [$method, $url]) {
        $this->withToken(WRITE_TOKEN)->json($method, $url, ['title' => 'x', 'body' => 'x', 'at' => ['lon' => 0, 'lat' => 0, 'height' => 0]])->assertNotFound();
    }

    expect($their->refresh()->closed_at)->toBeNull()
        ->and($theirAlert->refresh()->acknowledged_at)->toBeNull()
        ->and($theirs->issues()->count())->toBe(1);
});

it('adds readings from systems that measure without a gateway, within the time readings are kept', function (): void {
    $this->freezeSecond();
    [$twin] = writableTwin();
    $now = now()->getTimestampMs();

    $this->withToken(WRITE_TOKEN)->postJson(route('api.v1.twins.readings.store', $twin), ['readings' => [
        ['asset' => 'store', 'metric' => 'tempC', 'value' => '2.5', 'at' => $now - 200 * 86_400_000],
        ['asset' => 'store', 'metric' => 'tempC', 'value' => 3, 'at' => (string) $now],
    ]])->assertAccepted()->assertExactJson(['stored' => 2]);

    expect(Reading::query()->where('twin_id', $twin->id)->oldest('recorded_at')->pluck('value')->all())->toBe([2.5, 3.0]);

    $this->withToken(WRITE_TOKEN)->postJson(route('api.v1.twins.readings.store', $twin), ['readings' => [
        ['asset' => 'store', 'metric' => 'tempC', 'value' => 1, 'at' => $now - 400 * 86_400_000],
        ['asset' => 'pump', 'metric' => 'tempC', 'value' => 1, 'at' => $now],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['readings.0.at', 'readings.1.asset']);
});

it('only adds readings to twins in the token organisation, and with tokens that may write', function (): void {
    [$twin] = writableTwin();
    $elsewhere = twinIn(Organisation::factory()->create());
    $readings = ['readings' => [['asset' => 'store', 'metric' => 'tempC', 'value' => 1, 'at' => now()->getTimestampMs()]]];

    $this->withToken(WRITE_TOKEN)->postJson(route('api.v1.twins.readings.store', $elsewhere), $readings)->assertNotFound();

    ApiToken::factory()->for($twin->organisation)->token('mrd_reporting-token-that-only-reads-abcdefgh')->create();
    $this->withToken('mrd_reporting-token-that-only-reads-abcdefgh')->postJson(route('api.v1.twins.readings.store', $twin), $readings)
        ->assertForbidden();

    expect(Reading::query()->count())->toBe(0);
});

<?php

declare(strict_types=1);

use App\Enums\AlertSeverity;
use App\Enums\NotificationChannelKind;
use App\Enums\OrganisationRole;
use App\Mail\AlertMail;
use App\Models\NotificationChannel;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function channelForm(array $overrides = []): array
{
    return [
        'name' => 'Night shift',
        'kind' => 'email',
        'target' => "ops@example.com,\noncall@example.com",
        'min_severity' => 'warning',
        'notify_cleared' => true,
        'escalate_after_minutes' => '',
        'quiet_from' => '',
        'quiet_until' => '',
        'timezone' => 'Pacific/Auckland',
        ...$overrides,
    ];
}

it('adds an email channel', function (): void {
    [$user, $organisation] = member(OrganisationRole::Admin);
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.notification-channels.store', $project), channelForm(['quiet_from' => '22:00', 'quiet_until' => '06:30']))
        ->assertRedirectToRoute('projects.show', $project)
        ->assertInertiaFlash('toast.message', 'Night shift added.')
        ->assertInertiaFlashMissing('channelSecret');

    $channel = $project->notificationChannels()->sole();
    expect($channel->kind)->toBe(NotificationChannelKind::Email)
        ->and($channel->addresses())->toBe(['ops@example.com', 'oncall@example.com'])
        ->and($channel->min_severity)->toBe(AlertSeverity::Warning)
        ->and($channel->quiet_from)->toBe('22:00')
        ->and($channel->quiet_until)->toBe('06:30')
        ->and($channel->timezone)->toBe('Pacific/Auckland')
        ->and($channel->escalate_after_minutes)->toBeNull()
        ->and($channel->notify_issues)->toBeFalse()
        ->and($channel->secret)->toBeNull();
});

it('adds a channel that takes issue updates too', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $this->actingAs($user)->post(route('projects.notification-channels.store', $project), channelForm(['notify_issues' => true]))->assertSessionHasNoErrors();

    expect($project->notificationChannels()->sole()->notify_issues)->toBeTrue();
});

it('adds a webhook with a signing secret, shown once', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();

    $response = $this->actingAs($user)->post(route('projects.notification-channels.store', $project), channelForm([
        'kind' => 'webhook',
        'target' => ' https://93.184.216.34/meridian ',
        'min_severity' => 'critical',
        'notify_cleared' => false,
        'escalate_after_minutes' => '15',
    ]))->assertSessionHasNoErrors();

    $channel = $project->notificationChannels()->sole();
    $response->assertInertiaFlash('channelSecret', ['channelId' => $channel->id, 'secret' => $channel->secret]);
    expect($channel->target)->toBe('https://93.184.216.34/meridian')
        ->and($channel->secret)->toStartWith('whsec_')
        ->and($channel->notify_cleared)->toBeFalse()
        ->and($channel->escalate_after_minutes)->toBe(15)
        ->and($channel->quiet_from)->toBeNull();
});

it("refuses channels it can't or mustn't send to", function (array $form, string $field, string $error): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    NotificationChannel::factory()->for($project)->create(['name' => 'Taken']);

    $this->actingAs($user)->post(route('projects.notification-channels.store', $project), channelForm($form))
        ->assertSessionHasErrors([$field => $error]);

    expect($project->notificationChannels()->count())->toBe(1);
})->with([
    'a name in use' => [['name' => 'Taken'], 'name', 'The name has already been taken.'],
    'a bad address' => [['target' => 'ops@example.com, nobody'], 'target', 'Enter up to 20 email addresses, separated by commas or new lines.'],
    'too many addresses' => [['target' => implode(',', array_map(fn (int $i): string => "ops{$i}@example.com", range(1, 21)))], 'target', 'Enter up to 20 email addresses, separated by commas or new lines.'],
    'a private webhook' => [['kind' => 'webhook', 'target' => 'https://192.168.1.10/hook'], 'target', "192.168.1.10 is on a private network, which webhooks can't reach."],
    'Slack elsewhere' => [['kind' => 'slack', 'target' => 'https://example.com/hook'], 'target', 'A Slack webhook URL starts with https://hooks.slack.com/.'],
    'half the quiet hours' => [['quiet_from' => '22:00'], 'quiet_until', 'The quiet until field is required when quiet from is present.'],
    'no time zone' => [['timezone' => 'Mars/Olympus'], 'timezone', 'The timezone field must be a valid timezone.'],
    'an unknown kind' => [['kind' => 'pager'], 'kind', 'The selected kind is invalid.'],
    'a day too long to wait' => [['escalate_after_minutes' => 1441], 'escalate_after_minutes', 'The escalate after minutes field must not be greater than 1440.'],
]);

it('keeps operators and viewers from managing channels', function (OrganisationRole $role): void {
    [$user, $organisation] = member($role);
    $channel = NotificationChannel::factory()->for(Project::factory()->for($organisation))->create();

    $this->actingAs($user)->post(route('projects.notification-channels.store', $channel->project), channelForm())->assertForbidden();
    $this->actingAs($user)->delete(route('projects.notification-channels.destroy', [$channel->project, $channel]))->assertForbidden();
    $this->actingAs($user)->post(route('projects.notification-channels.test', [$channel->project, $channel]))->assertForbidden();
})->with([OrganisationRole::Operator, OrganisationRole::Viewer]);

it('deletes a channel', function (): void {
    [$user, $organisation] = member();
    $channel = NotificationChannel::factory()->for(Project::factory()->for($organisation))->create(['name' => 'Night shift']);

    $this->actingAs($user)->delete(route('projects.notification-channels.destroy', [$channel->project, $channel]))
        ->assertRedirectToRoute('projects.show', $channel->project)
        ->assertInertiaFlash('toast.message', 'Night shift deleted.');

    $this->assertModelMissing($channel);
});

it('finds a channel only in its own project', function (): void {
    [$user, $organisation] = member();
    $project = Project::factory()->for($organisation)->create();
    $elsewhere = NotificationChannel::factory()->create();

    $this->actingAs($user)->delete(route('projects.notification-channels.destroy', [$project, $elsewhere]))->assertNotFound();
});

it('sends a test, and says when it worked', function (): void {
    Mail::fake();
    [$user, $organisation] = member();
    $channel = NotificationChannel::factory()->for(Project::factory()->for($organisation))->create(['name' => 'Night shift', 'last_error' => 'old']);

    $this->actingAs($user)->post(route('projects.notification-channels.test', [$channel->project, $channel]))
        ->assertRedirectToRoute('projects.show', $channel->project)
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Test sent to Night shift.']);

    Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->alert->test);
    expect($channel->refresh()->last_error)->toBeNull()
        ->and($channel->last_sent_at)->not->toBeNull();
});

it('sends a test, and says why it failed', function (): void {
    Http::fake(['*' => Http::response('channel_not_found', 404)]);
    [$user, $organisation] = member();
    $channel = NotificationChannel::factory()->for(Project::factory()->for($organisation))
        ->webhook(NotificationChannelKind::Slack, 'https://hooks.slack.com/services/x')->create(['name' => 'Slack']);

    $this->actingAs($user)->post(route('projects.notification-channels.test', [$channel->project, $channel]))
        ->assertRedirectToRoute('projects.show', $channel->project)
        ->assertInertiaFlash('toast.type', 'error');

    expect($channel->refresh()->last_error)->toContain('404')
        ->and($channel->last_sent_at)->toBeNull();
});

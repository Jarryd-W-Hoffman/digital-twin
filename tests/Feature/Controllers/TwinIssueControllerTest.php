<?php

declare(strict_types=1);

use App\Enums\IssueEvent;
use App\Enums\OrganisationRole;
use App\Jobs\DeliverIssueNotification;
use App\Mail\IssueMail;
use App\Models\Alert;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function issueFields(array $overrides = []): array
{
    return [
        'title' => 'Camera 3 is blind behind the racking',
        'body' => "Can't see the back aisle.",
        'at' => ['lon' => 174.76, 'lat' => -36.85, 'height' => 6.5],
        'view' => ['lon' => 174.761, 'lat' => -36.851, 'height' => 70, 'heading' => 20, 'pitch' => -30, 'roll' => 0],
        ...$overrides,
    ];
}

it('lets anyone who can see the twin raise an issue, and opens it', function (): void {
    Storage::fake('local');
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);

    $response = $this->actingAs($user)->post(route('twins.issues.store', $twin), issueFields(['photo' => UploadedFile::fake()->image('aisle.jpg')]));

    $issue = $twin->issues()->sole();
    $response->assertRedirect(route('twins.show', [$twin, 'issue' => $issue->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Issue 1 raised.']);
    expect($issue->title)->toBe('Camera 3 is blind behind the racking')
        ->and($issue->author_id)->toBe($user->id)
        ->and($issue->comments()->sole()->photo)->toEndWith('.jpg');
});

it('raises an issue with only a title', function (): void {
    [$user, $organisation] = member(OrganisationRole::Operator);
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.issues.store', $twin), issueFields(['body' => '']))->assertSessionHasNoErrors();

    expect($twin->issues()->sole()->comments()->count())->toBe(0);
});

it('checks what an issue is raised with', function (array $fields, string $error): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.issues.store', $twin), issueFields($fields))->assertSessionHasErrors($error);
    expect($twin->issues()->count())->toBe(0);
})->with([
    'no title' => [['title' => ''], 'title'],
    'a long title' => [['title' => str_repeat('a', 201)], 'title'],
    'off the Earth' => [['at' => ['lon' => 190, 'lat' => 0, 'height' => 0]], 'at.lon'],
    'no camera' => [['view' => null], 'view'],
    'a camera upside down' => [['view' => ['lon' => 0, 'lat' => 0, 'height' => 10, 'heading' => 0, 'pitch' => 120, 'roll' => 0]], 'view.pitch'],
    'not a photo' => [['photo' => UploadedFile::fake()->create('notes.svg', 1, 'image/svg+xml')], 'photo'],
]);

it('keeps outsiders out of issues', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    $issue = Issue::factory()->for($twin)->create();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->post(route('twins.issues.store', $twin), issueFields())->assertForbidden();
    $this->actingAs($outsider)->post(route('twins.issues.comments.store', [$twin, $issue]), ['body' => 'Hi'])->assertForbidden();
    $this->actingAs($outsider)->post(route('twins.issues.closure.store', [$twin, $issue]))->assertForbidden();
    $this->actingAs($outsider)->delete(route('twins.issues.destroy', [$twin, $issue]))->assertForbidden();
});

it('only finds an issue through its own twin', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $elsewhere = Issue::factory()->for(twinIn($organisation))->create();

    $this->actingAs($user)->delete(route('twins.issues.destroy', [$twin, $elsewhere]))->assertNotFound();
    expect($elsewhere->fresh())->not->toBeNull();
});

it('adds comments with words or a photo', function (): void {
    Storage::fake('local');
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $issue = Issue::factory()->for($twin)->create();

    $this->actingAs($user)->from(route('twins.show', [$twin, 'issue' => $issue->id]))
        ->post(route('twins.issues.comments.store', [$twin, $issue]), ['body' => 'Seen it too.'])
        ->assertRedirect(route('twins.show', [$twin, 'issue' => $issue->id]));
    $this->actingAs($user)->post(route('twins.issues.comments.store', [$twin, $issue]), ['photo' => UploadedFile::fake()->image('it.jpg')])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('twins.issues.comments.store', [$twin, $issue]), ['body' => ''])
        ->assertSessionHasErrors(['body' => 'Write something or add a photo.']);

    expect($issue->comments()->pluck('body')->all())->toBe(['Seen it too.', null]);
});

it('lets the author or an editor delete a comment', function (): void {
    [$author, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $issue = Issue::factory()->for($twin)->create();
    $mine = IssueComment::factory()->for($issue)->create(['author_id' => $author->id]);
    $theirs = IssueComment::factory()->for($issue)->create();
    $editor = User::factory()->create();
    $organisation->memberships()->create(['user_id' => $editor->id, 'role' => OrganisationRole::Admin]);

    $this->actingAs($author)->delete(route('twins.issues.comments.destroy', [$twin, $issue, $theirs]))->assertForbidden();
    $this->actingAs($author)->delete(route('twins.issues.comments.destroy', [$twin, $issue, $mine]))->assertRedirect();
    $this->actingAs($editor)->delete(route('twins.issues.comments.destroy', [$twin, $issue, $theirs]))->assertRedirect();

    expect($issue->comments()->count())->toBe(0);
});

it('lets the author or an operator close and reopen an issue', function (): void {
    [$author, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $issue = Issue::factory()->for($twin)->create(['author_id' => $author->id]);
    $viewer = User::factory()->create();
    $organisation->memberships()->create(['user_id' => $viewer->id, 'role' => OrganisationRole::Viewer]);
    $operator = User::factory()->create();
    $organisation->memberships()->create(['user_id' => $operator->id, 'role' => OrganisationRole::Operator]);

    $this->actingAs($viewer)->post(route('twins.issues.closure.store', [$twin, $issue]))->assertForbidden();
    $this->actingAs($author)->post(route('twins.issues.closure.store', [$twin, $issue]))->assertRedirect();
    expect($issue->fresh()?->closed_by)->toBe($author->id);

    $this->actingAs($operator)->delete(route('twins.issues.closure.destroy', [$twin, $issue]))->assertRedirect();
    expect($issue->fresh()?->closed_at)->toBeNull();
});

it('lets the author or an editor delete an issue', function (): void {
    Storage::fake('local');
    [$owner, $organisation] = member();
    $twin = twinIn($organisation);
    $issue = Issue::factory()->for($twin)->create();
    $operator = User::factory()->create();
    $organisation->memberships()->create(['user_id' => $operator->id, 'role' => OrganisationRole::Operator]);

    $this->actingAs($operator)->delete(route('twins.issues.destroy', [$twin, $issue]))->assertForbidden();
    $this->actingAs($owner)->delete(route('twins.issues.destroy', [$twin, $issue]))
        ->assertRedirect(route('twins.show', $twin))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Issue deleted.']);

    expect($issue->fresh())->toBeNull();
});

it('serves a photo as an image only, to those who can see the twin', function (): void {
    Storage::fake('local');
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $issue = Issue::factory()->for($twin)->create();
    $photo = IssueComment::factory()->for($issue)->create(['photo' => $issue->storagePrefix().'/crack.png']);
    $words = IssueComment::factory()->for($issue)->create();
    $gone = IssueComment::factory()->for($issue)->create(['photo' => $issue->storagePrefix().'/gone.jpg']);
    $odd = IssueComment::factory()->for($issue)->create(['photo' => $issue->storagePrefix().'/odd.bin']);
    Storage::disk('local')->put((string) $photo->photo, 'png');
    Storage::disk('local')->put((string) $odd->photo, 'bin');

    $this->actingAs($user)->get(route('twins.issues.comments.photo', [$twin, $issue, $photo]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    $this->actingAs($user)->get(route('twins.issues.comments.photo', [$twin, $issue, $odd]))->assertHeader('Content-Type', 'application/octet-stream');
    $this->actingAs($user)->get(route('twins.issues.comments.photo', [$twin, $issue, $words]))->assertNotFound();
    $this->actingAs($user)->get(route('twins.issues.comments.photo', [$twin, $issue, $gone]))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('twins.issues.comments.photo', [$twin, $issue, $photo]))->assertForbidden();
});

it("raises an issue from one of the twin's alerts, and tells the channels that take issues", function (): void {
    Queue::fake();
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $alert = Alert::factory()->for($twin)->create();
    $issues = NotificationChannel::factory()->for($twin->project)->issues()->create();
    NotificationChannel::factory()->for($twin->project)->create();

    $this->actingAs($user)->post(route('twins.issues.store', $twin), issueFields(['alert' => $alert->id]))->assertSessionHasNoErrors();

    expect($twin->issues()->sole()->alert_id)->toBe($alert->id);
    Queue::assertPushed(DeliverIssueNotification::class, 1);
    Queue::assertPushed(fn (DeliverIssueNotification $job): bool => $job->channel->is($issues) && $job->message->event === IssueEvent::Raised);
});

it("won't follow up another twin's alert", function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $elsewhere = Alert::factory()->create();

    $this->actingAs($user)->post(route('twins.issues.store', $twin), issueFields(['alert' => $elsewhere->id]))->assertSessionHasErrors('alert');
    expect($twin->issues()->count())->toBe(0);
});

it('lets whoever has an issue close it', function (): void {
    [$assignee, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $issue = Issue::factory()->for($twin)->assigned($assignee)->create();

    $this->actingAs($assignee)->post(route('twins.issues.closure.store', [$twin, $issue]))->assertRedirect();

    expect($issue->fresh()?->closed_by)->toBe($assignee->id);
});

it('lets the author or an operator give an issue to a member, by a date', function (): void {
    Mail::fake();
    [$author, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    $issue = Issue::factory()->for($twin)->create(['author_id' => $author->id, 'title' => 'Gate sticks']);
    $fitter = User::factory()->create(['name' => 'Ana Fitter']);
    $organisation->memberships()->create(['user_id' => $fitter->id, 'role' => OrganisationRole::Viewer]);
    $other = User::factory()->create();
    $organisation->memberships()->create(['user_id' => $other->id, 'role' => OrganisationRole::Viewer]);
    $operator = User::factory()->create();
    $organisation->memberships()->create(['user_id' => $operator->id, 'role' => OrganisationRole::Operator]);
    $assign = fn (User $as, array $fields) => $this->actingAs($as)->from(route('twins.show', $twin))
        ->patch(route('twins.issues.assignment.update', [$twin, $issue]), $fields);

    $assign($other, ['assignee' => $other->id])->assertForbidden();
    $assign($author, ['assignee' => $fitter->id, 'due_on' => '2026-11-20'])->assertRedirect(route('twins.show', $twin));

    $issue->refresh();
    expect($issue->assignee_id)->toBe($fitter->id)
        ->and($issue->due_on?->format('Y-m-d'))->toBe('2026-11-20');
    Mail::assertQueued(IssueMail::class, fn (IssueMail $mail): bool => $mail->hasTo($fitter->email)
        && $mail->toAssignee
        && $mail->envelope()->subject === sprintf('Issue #1 is yours: Gate sticks (%s)', $twin->name));

    $assign($operator, ['assignee' => '', 'due_on' => ''])->assertRedirect();
    expect($issue->fresh()?->assignee_id)->toBeNull()
        ->and($issue->fresh()?->due_on)->toBeNull();
    Mail::assertQueuedCount(1);
});

it('checks who an issue is given to and when it is due', function (array $fields, string $error): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $issue = Issue::factory()->for($twin)->create();
    $outsider = User::factory()->create();

    $this->actingAs($user)->patch(route('twins.issues.assignment.update', [$twin, $issue]), array_map(fn (string $value): string => $value === 'outsider' ? $outsider->id : $value, $fields))
        ->assertSessionHasErrors($error);
})->with([
    'someone outside the organisation' => [['assignee' => 'outsider'], 'assignee'],
    'not a person' => [['assignee' => 'nobody'], 'assignee'],
    'not a date' => [['due_on' => 'next week'], 'due_on'],
    'a date out of reach' => [['due_on' => '2300-01-01'], 'due_on'],
]);

it('only assigns an issue through its own twin', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $elsewhere = Issue::factory()->create();

    $this->actingAs($user)->patch(route('twins.issues.assignment.update', [$twin, $elsewhere]), ['assignee' => $user->id])->assertNotFound();
});

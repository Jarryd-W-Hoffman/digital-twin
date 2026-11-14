<?php

declare(strict_types=1);

use App\Actions\LinkSsoIdentity;
use App\Actions\SignInWithSso;
use App\Actions\UnlinkSsoIdentity;
use App\Enums\AuditAction;
use App\Enums\OrganisationRole;
use App\Models\AuditEvent;
use App\Models\SsoConnection;
use App\Models\SsoIdentity;
use App\Models\User;
use Illuminate\Validation\ValidationException;

const ANA = ['subject' => 'ana-at-harbour', 'email' => 'ana@harbour.example', 'name' => 'Ana Ruiz'];

it('signs in someone it has signed in before, by subject, whatever their email now', function (): void {
    $this->freezeSecond();
    $connection = SsoConnection::factory()->domains()->create();
    [$user] = [User::factory()->create()];
    $connection->organisation->memberships()->create(['user_id' => $user->id, 'role' => OrganisationRole::Viewer]);
    $identity = SsoIdentity::factory()->for($connection, 'connection')->for($user)->create(['subject' => 'ana-at-harbour']);

    $signedIn = resolve(SignInWithSso::class)->handle($connection, [...ANA, 'email' => 'ana.ruiz@elsewhere.example']);

    expect($signedIn->is($user))->toBeTrue()
        ->and($identity->fresh())->email->toBe('ana.ruiz@elsewhere.example')->last_signed_in_at->toEqual(now());
});

it('turns away someone it signed in before who was removed from the organisation', function (): void {
    $connection = SsoConnection::factory()->domains()->autoJoin()->create();
    SsoIdentity::factory()->for($connection, 'connection')->create(['subject' => 'ana-at-harbour']);

    resolve(SignInWithSso::class)->handle($connection, ANA);
})->throws(ValidationException::class, "You're no longer a member of");

it('never takes over an existing account by its email', function (): void {
    $connection = SsoConnection::factory()->domains()->autoJoin()->create();
    User::factory()->create(['email' => 'ana@harbour.example']);

    resolve(SignInWithSso::class)->handle($connection, ANA);
})->throws(ValidationException::class, 'You already have a Meridian account.');

it('adds someone new as a member when the organisation lets people join', function (): void {
    $connection = SsoConnection::factory()->domains()->autoJoin(OrganisationRole::Operator)->create();

    $user = resolve(SignInWithSso::class)->handle($connection, ANA);

    expect($user)
        ->name->toBe('Ana Ruiz')
        ->email->toBe('ana@harbour.example')
        ->email_verified_at->not->toBeNull()
        ->current_organisation_id->toBe($connection->organisation_id)
        ->and($user->roleIn($connection->organisation))->toBe(OrganisationRole::Operator)
        ->and($connection->identities()->sole())->user_id->toBe($user->id)->subject->toBe('ana-at-harbour')
        ->and(AuditEvent::query()->sole())
        ->action->toBe(AuditAction::MemberAdded)
        ->subject->toBe('ana@harbour.example')
        ->details->toBe(['role' => 'operator', 'via' => 'single sign-on'])
        ->actorLabel()->toBe('Meridian');
});

it('names someone new after their email when the provider gives no name', function (): void {
    $connection = SsoConnection::factory()->domains()->autoJoin()->create();

    expect(resolve(SignInWithSso::class)->handle($connection, [...ANA, 'name' => null])->name)->toBe('ana');
});

it('turns away someone new unless the organisation lets them join from its domains', function (bool $autoJoin, string $email): void {
    $connection = SsoConnection::factory()->domains()->create(['auto_join' => $autoJoin]);

    expect(fn () => resolve(SignInWithSso::class)->handle($connection, [...ANA, 'email' => $email]))
        ->toThrow(ValidationException::class, "hasn't added you yet");
    expect(User::query()->where('email', $email)->exists())->toBeFalse();
})->with([
    'joining off' => [false, 'ana@harbour.example'],
    'another domain' => [true, 'ana@gmail.example'],
]);

it('links an account to its identity, and unlinks it', function (): void {
    $connection = SsoConnection::factory()->domains()->create();
    $user = User::factory()->create();

    $identity = resolve(LinkSsoIdentity::class)->handle($user, $connection, ANA);
    resolve(LinkSsoIdentity::class)->handle($user, $connection, ANA);
    resolve(LinkSsoIdentity::class)->handle($user, $connection, [...ANA, 'subject' => 'ana-2']);

    expect($connection->identities()->sole())->user_id->toBe($user->id)->subject->toBe('ana-2');

    resolve(UnlinkSsoIdentity::class)->handle($identity->fresh());

    expect($connection->identities()->count())->toBe(0)
        ->and(AuditEvent::query()->oldest('created_at')->oldest('id')->pluck('action')->all())->toBe([AuditAction::SsoLinked, AuditAction::SsoLinked, AuditAction::SsoUnlinked]);
});

it("won't link an identity that is someone else's", function (): void {
    $connection = SsoConnection::factory()->domains()->create();
    SsoIdentity::factory()->for($connection, 'connection')->create(['subject' => 'ana-at-harbour']);

    resolve(LinkSsoIdentity::class)->handle(User::factory()->create(), $connection, ANA);
})->throws(ValidationException::class, 'ana@harbour.example is already linked to another Meridian account.');

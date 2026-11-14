<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\SsoConnection;
use App\Models\SsoIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finds who an identity provider signed in. Someone it has signed in before
 * is found by the subject it gave them. Someone new gets an account and
 * joins the organisation, if it lets people join and their email is in its
 * domains. An existing account is never taken over by email: its owner
 * links it from their settings, signed in the usual way.
 */
final readonly class SignInWithSso
{
    public function __construct(private CreateUser $users, private RecordAuditEvent $audit) {}

    /**
     * @param  array{subject: string, email: string, name: string|null}  $claims
     *
     * @throws ValidationException why they can't be signed in, under email
     */
    public function handle(SsoConnection $connection, array $claims): User
    {
        $organisation = $connection->organisation;
        $identity = $connection->identities()->where('subject', $claims['subject'])->first();

        if ($identity instanceof SsoIdentity) {
            $user = $identity->user;

            throw_if($user->roleIn($organisation) === null, ValidationException::withMessages([
                'email' => __("You're no longer a member of :organisation. Ask one of its admins to add you again.", ['organisation' => $organisation->name]),
            ]));

            $identity->update(['email' => $claims['email'], 'last_signed_in_at' => now()]);

            return $user;
        }

        throw_if(User::query()->where('email', $claims['email'])->exists(), ValidationException::withMessages([
            'email' => __("You already have a Meridian account. Sign in with your password or passkey, then link :organisation's single sign-on under Settings, Single sign-on.", ['organisation' => $organisation->name]),
        ]));

        throw_unless($connection->auto_join && $connection->covers($claims['email']), ValidationException::withMessages([
            'email' => __(":organisation hasn't added you yet. Ask one of its admins to add you.", ['organisation' => $organisation->name]),
        ]));

        return DB::transaction(function () use ($connection, $organisation, $claims): User {
            $user = $this->users->handle([
                'name' => $claims['name'] ?? Str::before($claims['email'], '@'),
                'email' => $claims['email'],
                'email_verified_at' => now(),
                'current_organisation_id' => $organisation->id,
            ], Str::password(48));

            $organisation->memberships()->create(['user_id' => $user->id, 'role' => $connection->default_role]);
            $connection->identities()->create([
                'user_id' => $user->id,
                'subject' => $claims['subject'],
                'email' => $claims['email'],
                'last_signed_in_at' => now(),
            ]);

            $this->audit->handle(AuditAction::MemberAdded, $organisation, $claims['email'], ['role' => $connection->default_role->value, 'via' => 'single sign-on']);

            return $user;
        });
    }
}

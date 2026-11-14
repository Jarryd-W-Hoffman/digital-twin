<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\SsoConnection;
use App\Models\SsoIdentity;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Links someone's account, while they're signed in, to who the identity
 * provider says they are, so they can sign in through it from then on.
 */
final readonly class LinkSsoIdentity
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * @param  array{subject: string, email: string, name: string|null}  $claims
     *
     * @throws ValidationException when that identity is someone else's
     */
    public function handle(User $user, SsoConnection $connection, array $claims): SsoIdentity
    {
        $taken = $connection->identities()->where('subject', $claims['subject'])->where('user_id', '!=', $user->id)->exists();

        throw_if($taken, ValidationException::withMessages([
            'sso' => __(':email is already linked to another Meridian account.', ['email' => $claims['email']]),
        ]));

        $identity = $connection->identities()->updateOrCreate(['user_id' => $user->id], [
            'subject' => $claims['subject'],
            'email' => $claims['email'],
            'last_signed_in_at' => now(),
        ]);

        // Signing in again through it to work in the organisation isn't news.
        if ($identity->wasRecentlyCreated || $identity->wasChanged('subject')) {
            $this->audit->handle(AuditAction::SsoLinked, $connection->organisation, $user->email, ['identity' => $claims['email']]);
        }

        return $identity;
    }
}

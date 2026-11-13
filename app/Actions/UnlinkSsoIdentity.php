<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\SsoIdentity;

/**
 * Stops someone signing in through an identity provider. They sign in with
 * their password or passkey instead.
 */
final readonly class UnlinkSsoIdentity
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(SsoIdentity $identity): void
    {
        $identity->delete();

        $this->audit->handle(AuditAction::SsoUnlinked, $identity->connection->organisation, $identity->user->email, ['identity' => $identity->email]);
    }
}

<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\SsoConnection;

/**
 * Turns single sign-on off. Its people keep their accounts and sign in with
 * a password or passkey; the links to their identities go.
 */
final readonly class DeleteSsoConnection
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(SsoConnection $connection): void
    {
        $connection->delete();

        $this->audit->handle(AuditAction::SsoRemoved, $connection->organisation, $connection->issuer);
    }
}

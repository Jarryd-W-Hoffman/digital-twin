<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\TwinShareLink;

final readonly class RevokeShareLink
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * The link stops opening the twin and loading its files at once; a page
     * already open stops following it live within ten minutes.
     */
    public function handle(TwinShareLink $link): void
    {
        $link->delete();

        $this->audit->handle(AuditAction::ShareLinkRevoked, $link->twin, $link->name);
    }
}

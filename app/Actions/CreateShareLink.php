<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\Twin;
use App\Models\TwinShareLink;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

final readonly class CreateShareLink
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Makes a link that shows the twin, read only, until it expires. The
     * token in it is returned once; only its hash is kept.
     *
     * @return array{TwinShareLink, string}
     */
    public function handle(Twin $twin, User $creator, string $name, CarbonInterface $expiresAt, bool $live): array
    {
        $token = Str::random(40);

        $link = $twin->shareLinks()->create([
            'created_by' => $creator->id,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'hint' => mb_substr($token, -4),
            'live' => $live,
            'expires_at' => $expiresAt,
        ]);

        $this->audit->handle(AuditAction::ShareLinkCreated, $twin, $name, ['expiresAt' => $expiresAt->toIso8601String(), 'live' => $live ? 'yes' : 'no']);

        return [$link, $token];
    }
}

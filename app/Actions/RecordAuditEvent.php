<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\Twin;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * Notes in the organisation's audit log that something was done, by the
 * signed-in person, the API token the request carried, or (with neither,
 * as in a scheduled job or the seeder) Meridian itself, which keeps no
 * address.
 */
final readonly class RecordAuditEvent
{
    /**
     * The hidden context key the API puts its token's name under.
     */
    public const string API_TOKEN = 'audit.apiToken';

    /**
     * @param  array<string, mixed>  $details
     */
    public function handle(AuditAction $action, Organisation|Project|Twin $where, ?string $subject = null, array $details = []): AuditEvent
    {
        $token = Context::getHidden(self::API_TOKEN);
        $user = is_string($token) ? null : Auth::user();

        $project = match (true) {
            $where instanceof Project => $where,
            $where instanceof Twin => $where->project,
            default => null,
        };

        return AuditEvent::query()->create([
            'organisation_id' => $where instanceof Organisation ? $where->id : $where->organisation_id,
            'actor_id' => $user instanceof User ? $user->id : null,
            'actor_name' => $user instanceof User ? $user->name : null,
            'actor_api' => is_string($token) ? $token : null,
            'action' => $action,
            'subject' => $subject,
            'project_id' => $project?->id,
            'project_name' => $project?->name,
            'twin_id' => $where instanceof Twin ? $where->id : null,
            'twin_name' => $where instanceof Twin ? $where->name : null,
            'details' => $details === [] ? null : $details,
            'ip' => $user instanceof User || is_string($token) ? request()->ip() : null,
            'created_at' => now(),
        ]);
    }
}

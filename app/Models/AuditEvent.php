<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditAction;
use App\Support\Actor;
use Carbon\CarbonInterface;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing someone did in an organisation: who, what, where, and when.
 * Kept for the audit retention period, then pruned.
 *
 * @property-read string $id
 * @property-read string $organisation_id
 * @property-read string|null $actor_id
 * @property-read string|null $actor_name
 * @property-read string|null $actor_api
 * @property-read AuditAction $action
 * @property-read string|null $subject
 * @property-read string|null $project_id
 * @property-read string|null $project_name
 * @property-read string|null $twin_id
 * @property-read string|null $twin_name
 * @property-read array<string, mixed>|null $details
 * @property-read string|null $ip
 * @property-read CarbonInterface $created_at
 * @property-read Organisation $organisation
 */
final class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    use HasUuids;
    use MassPrunable;

    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'organisation_id' => 'string',
            'actor_id' => 'string',
            'actor_name' => 'string',
            'actor_api' => 'string',
            'action' => AuditAction::class,
            'subject' => 'string',
            'project_id' => 'string',
            'project_name' => 'string',
            'twin_id' => 'string',
            'twin_name' => 'string',
            'details' => 'array',
            'ip' => 'string',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * Who did it: a person, an API token, or Meridian itself.
     */
    public function actorLabel(): string
    {
        return $this->actor_name ?? Actor::apiName($this->actor_api) ?? __('Meridian');
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays(config()->integer('meridian.audit.retention_days')));
    }
}

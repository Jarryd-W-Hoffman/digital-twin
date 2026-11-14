<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ScimUserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone an identity provider provisions through SCIM: its SCIM User, kept
 * against the Meridian account it stands for.
 *
 * @property-read string $id
 * @property-read string $sso_connection_id
 * @property-read string $user_id
 * @property-read string $user_name
 * @property-read string|null $external_id
 * @property-read bool $active
 * @property-read bool $created_account
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read SsoConnection $connection
 * @property-read User $user
 */
final class ScimUser extends Model
{
    /** @use HasFactory<ScimUserFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'sso_connection_id' => 'string',
            'user_id' => 'string',
            'user_name' => 'string',
            'external_id' => 'string',
            'active' => 'boolean',
            'created_account' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SsoConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SsoConnection::class, 'sso_connection_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

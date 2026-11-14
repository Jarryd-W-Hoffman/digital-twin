<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\SsoIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person as an organisation's identity provider knows them: by the
 * subject it gives them, which never changes, rather than by email.
 *
 * @property-read int $id
 * @property-read string $sso_connection_id
 * @property-read string $user_id
 * @property-read string $subject
 * @property-read string $email
 * @property-read CarbonInterface|null $last_signed_in_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read SsoConnection $connection
 * @property-read User $user
 */
final class SsoIdentity extends Model
{
    /** @use HasFactory<SsoIdentityFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'sso_connection_id' => 'string',
            'user_id' => 'string',
            'subject' => 'string',
            'email' => 'string',
            'last_signed_in_at' => 'datetime',
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

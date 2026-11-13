<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SsoDomainFactory;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An email domain that signs in through an organisation's identity provider.
 * Each domain belongs to one organisation at most.
 *
 * @property-read int $id
 * @property-read string $sso_connection_id
 * @property-read string $domain
 * @property-read SsoConnection $connection
 */
#[WithoutTimestamps]
final class SsoDomain extends Model
{
    /** @use HasFactory<SsoDomainFactory> */
    use HasFactory;

    /**
     * The domain of an email address, lower-cased.
     */
    public static function of(string $email): string
    {
        return Str::lower(Str::afterLast($email, '@'));
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'sso_connection_id' => 'string',
            'domain' => 'string',
        ];
    }

    /**
     * @return BelongsTo<SsoConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SsoConnection::class, 'sso_connection_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganisationRole;
use Carbon\CarbonInterface;
use Database\Factories\SsoConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organisation's OpenID Connect identity provider. People whose email is
 * in one of its domains sign in through it.
 *
 * @property-read string $id
 * @property-read string $organisation_id
 * @property-read string $issuer
 * @property-read string $client_id
 * @property-read string $client_secret
 * @property-read bool $required
 * @property-read bool $auto_join
 * @property-read OrganisationRole $default_role
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read Organisation $organisation
 * @property-read Collection<int, SsoDomain> $domains
 */
#[Hidden(['client_secret'])]
final class SsoConnection extends Model
{
    /** @use HasFactory<SsoConnectionFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'organisation_id' => 'string',
            'issuer' => 'string',
            'client_id' => 'string',
            'client_secret' => 'encrypted',
            'required' => 'boolean',
            'auto_join' => 'boolean',
            'default_role' => OrganisationRole::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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
     * @return HasMany<SsoDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(SsoDomain::class);
    }

    /**
     * @return HasMany<SsoIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(SsoIdentity::class);
    }

    /**
     * Whether an email address belongs to one of its domains.
     */
    public function covers(string $email): bool
    {
        return $this->domains->contains('domain', SsoDomain::of($email));
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Feature;
use App\Enums\OrganisationRole;
use Carbon\CarbonInterface;
use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * @property-read string $id
 * @property-read string $name
 * @property-read string $slug
 * @property-read Collection<int, Feature>|null $features
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'name' => 'string',
            'slug' => 'string',
            'features' => AsEnumCollection::of(Feature::class),
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Whether a feature is on for it: turned on, and offered by the server.
     */
    public function hasFeature(Feature $feature): bool
    {
        return ($this->features?->contains($feature) ?? false) && $feature->unavailable() === null;
    }

    /**
     * The identity provider its people sign in through, if it has one.
     *
     * @return HasOne<SsoConnection, $this>
     */
    public function ssoConnection(): HasOne
    {
        return $this->hasOne(SsoConnection::class);
    }

    /**
     * The keys other systems use to reach it through the API.
     *
     * @return HasMany<ApiToken, $this>
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * The organisation's library of custom asset types.
     *
     * @return HasMany<AssetType, $this>
     */
    public function assetTypes(): HasMany
    {
        return $this->hasMany(AssetType::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<Twin, $this>
     */
    public function twins(): HasMany
    {
        return $this->hasMany(Twin::class);
    }

    public function roleOf(User $user): ?OrganisationRole
    {
        return $this->memberships()->where('user_id', $user->id)->first()?->role;
    }
}

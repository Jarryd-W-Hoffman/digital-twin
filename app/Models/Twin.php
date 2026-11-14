<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TwinFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @property-read string $id
 * @property-read string $organisation_id
 * @property-read string $project_id
 * @property-read string $name
 * @property-read string|null $description
 * @property-read string|null $gateway_token_hash
 * @property-read string|null $gateway_url
 * @property-read CarbonInterface|null $gateway_seen_at
 * @property-read string|null $gateway_mqtt
 * @property-read array<string, string>|null $gateway_opcua
 * @property-read bool $opcua_writes
 * @property-read string|null $opening_view_id
 * @property-read Organisation $organisation
 * @property-read Project $project
 * @property-read TwinVersion|null $latestVersion
 * @property-read SavedView|null $openingView
 * @property-read int|null $open_issues_count
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[Hidden(['gateway_token_hash'])]
final class Twin extends Model
{
    /** @use HasFactory<TwinFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * A gateway polls every few seconds; after this long without a check-in
     * it is treated as offline.
     */
    public const int GATEWAY_TIMEOUT_SECONDS = 30;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'opcua_writes' => false,
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'organisation_id' => 'string',
            'project_id' => 'string',
            'name' => 'string',
            'description' => 'string',
            'gateway_token_hash' => 'string',
            'gateway_url' => 'string',
            'gateway_seen_at' => 'datetime',
            'gateway_mqtt' => 'string',
            'gateway_opcua' => 'array',
            'opcua_writes' => 'boolean',
            'opening_view_id' => 'string',
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Alert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * The alerts that haven't cleared: at most one per rule and asset.
     *
     * @return HasMany<Alert, $this>
     */
    public function openAlerts(): HasMany
    {
        return $this->alerts()->whereNull('cleared_at');
    }

    /**
     * The notes and problems pinned in its scene.
     *
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * The views people saved of it, private and shared.
     *
     * @return HasMany<SavedView, $this>
     */
    public function savedViews(): HasMany
    {
        return $this->hasMany(SavedView::class);
    }

    /**
     * @return HasMany<TwinShareLink, $this>
     */
    public function shareLinks(): HasMany
    {
        return $this->hasMany(TwinShareLink::class);
    }

    /**
     * The shared view it opens at, instead of the whole site.
     *
     * @return BelongsTo<SavedView, $this>
     */
    public function openingView(): BelongsTo
    {
        return $this->belongsTo(SavedView::class, 'opening_view_id');
    }

    /**
     * @return HasMany<OpcUaWrite, $this>
     */
    public function opcUaWrites(): HasMany
    {
        return $this->hasMany(OpcUaWrite::class);
    }

    /**
     * @return HasMany<TwinVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(TwinVersion::class);
    }

    /**
     * The highest-numbered version, by a correlated subquery: ofMany() also
     * takes max() of the uuid key, which Postgres doesn't have.
     *
     * @return HasOne<TwinVersion, $this>
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(TwinVersion::class)->where('twin_versions.number', fn (QueryBuilder $query): QueryBuilder => $query
            ->selectRaw('max(latest.number)')
            ->from('twin_versions', 'latest')
            ->whereColumn('latest.twin_id', 'twin_versions.twin_id'));
    }

    /**
     * Whether a gateway holding this twin's token has checked in recently.
     */
    public function gatewayOnline(): bool
    {
        return $this->gateway_token_hash !== null
            && $this->gateway_url !== null
            && $this->gateway_seen_at?->isAfter(now()->subSeconds(self::GATEWAY_TIMEOUT_SECONDS)) === true;
    }

    /**
     * Where its own files, such as issue photos, are kept on the assets disk.
     */
    public function storagePrefix(): string
    {
        return $this->project->storagePrefix().'/twins/'.$this->id;
    }

    /**
     * The broker login its gateway uses, with the gateway token as password.
     */
    public function mqttUsername(): string
    {
        return 'twin-'.$this->id;
    }
}

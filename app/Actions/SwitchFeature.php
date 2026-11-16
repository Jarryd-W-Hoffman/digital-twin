<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditAction;
use App\Enums\Feature;
use App\Models\Organisation;
use LogicException;

final readonly class SwitchFeature
{
    public function __construct(private RecordAuditEvent $audit) {}

    /**
     * Turns an optional feature on or off for an organisation, and keeps that
     * in its audit log. A feature this server can't offer can't be turned on.
     */
    public function handle(Organisation $organisation, Feature $feature, bool $on): void
    {
        throw_if($on && $feature->unavailable() !== null, LogicException::class, 'This server does not offer '.$feature->value.'.');

        /** @var array<string, Feature> $features */
        $features = [];

        foreach ($organisation->features ?? [] as $each) {
            $features[$each->value] = $each;
        }

        if (isset($features[$feature->value]) === $on) {
            return;
        }

        if ($on) {
            $features[$feature->value] = $feature;
        } else {
            unset($features[$feature->value]);
        }

        $organisation->update(['features' => array_values($features)]);

        $this->audit->handle($on ? AuditAction::FeatureTurnedOn : AuditAction::FeatureTurnedOff, $organisation, $feature->label());
    }
}

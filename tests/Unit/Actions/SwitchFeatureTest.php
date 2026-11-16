<?php

declare(strict_types=1);

use App\Actions\SwitchFeature;
use App\Enums\AuditAction;
use App\Enums\Feature;
use App\Models\AuditEvent;
use App\Models\Organisation;

beforeEach(function (): void {
    config(['meridian.photos.nodeodm_url' => 'http://nodeodm:3000']);
    $this->organisation = Organisation::factory()->create();
});

it('turns a feature on and off, and keeps each change in the audit log', function (): void {
    resolve(SwitchFeature::class)->handle($this->organisation, Feature::PhotoProcessing, true);

    expect($this->organisation->refresh()->hasFeature(Feature::PhotoProcessing))->toBeTrue();

    resolve(SwitchFeature::class)->handle($this->organisation, Feature::PhotoProcessing, false);

    expect($this->organisation->refresh()->hasFeature(Feature::PhotoProcessing))->toBeFalse()
        ->and($this->organisation->features?->all())->toBe([])
        ->and(AuditEvent::query()->orderBy('id')->get()->map(fn (AuditEvent $event): array => [$event->action, $event->subject])->all())->toBe([
            [AuditAction::FeatureTurnedOn, 'Photo processing'],
            [AuditAction::FeatureTurnedOff, 'Photo processing'],
        ]);
});

it('records nothing when a feature is already as asked', function (bool $on): void {
    $this->organisation->update(['features' => $on ? [Feature::PhotoProcessing] : []]);

    resolve(SwitchFeature::class)->handle($this->organisation, Feature::PhotoProcessing, $on);

    expect(AuditEvent::query()->count())->toBe(0)
        ->and($this->organisation->refresh()->features?->contains(Feature::PhotoProcessing))->toBe($on);
})->with([true, false]);

it('refuses to turn on what this server does not offer, but turns it off', function (): void {
    config(['meridian.photos.nodeodm_url' => null]);
    $this->organisation->update(['features' => [Feature::PhotoProcessing]]);

    expect(fn () => resolve(SwitchFeature::class)->handle($this->organisation, Feature::PhotoProcessing, true))
        ->toThrow(LogicException::class, 'This server does not offer photo-processing.');

    resolve(SwitchFeature::class)->handle($this->organisation, Feature::PhotoProcessing, false);

    expect($this->organisation->refresh()->features?->all())->toBe([]);
});

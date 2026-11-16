<?php

declare(strict_types=1);

use App\Enums\Feature;
use App\Models\Organisation;

it('says what each feature is', function (): void {
    expect(Feature::PhotoProcessing->label())->toBe('Photo processing')
        ->and(Feature::PhotoProcessing->description())->toStartWith('Turn drone photos into a survey');
});

it('offers photo processing only where NodeODM runs', function (): void {
    config(['meridian.photos.nodeodm_url' => null]);

    expect(Feature::PhotoProcessing->unavailable())->toBe('This server has no photo processor. Its administrator runs NodeODM and sets MERIDIAN_NODEODM_URL to offer it.');

    config(['meridian.photos.nodeodm_url' => 'http://nodeodm:3000']);

    expect(Feature::PhotoProcessing->unavailable())->toBeNull();
});

it('has a feature on for an organisation when it is turned on and offered', function (?array $features, ?string $nodeOdm, bool $on): void {
    config(['meridian.photos.nodeodm_url' => $nodeOdm]);
    $organisation = Organisation::factory()->create(['features' => $features]);

    expect($organisation->refresh()->hasFeature(Feature::PhotoProcessing))->toBe($on);
})->with([
    'turned on and offered' => [[Feature::PhotoProcessing], 'http://nodeodm:3000', true],
    'turned on, not offered' => [[Feature::PhotoProcessing], null, false],
    'never turned on' => [null, 'http://nodeodm:3000', false],
    'turned off' => [[], 'http://nodeodm:3000', false],
]);

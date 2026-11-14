<?php

declare(strict_types=1);

use App\Support\TwinDefinitionValidator;
use App\Support\TwinTemplates;

it('lists the templates with their names', function (): void {
    expect(resolve(TwinTemplates::class)->all())
        ->toHaveKeys(['empty', 'factory', 'mine', 'survey', 'power'])
        ->and(resolve(TwinTemplates::class)->all()['mine']['name'])->toBe('Mine site');
});

it('places every template at a location, and the validator accepts it', function (string $id): void {
    $definition = resolve(TwinTemplates::class)->definition($id, -36.85, 174.76, 12);

    expect($definition['origin'])->toBe(['lon' => 174.76, 'lat' => -36.85])
        ->and($definition['utcOffsetHours'])->toBe(12.0)
        ->and(resolve(TwinDefinitionValidator::class)->errors($definition))->toBe([]);
})->with(['empty', 'factory', 'mine', 'survey', 'power']);

it('leaves power out of all but the site power template', function (): void {
    $templates = resolve(TwinTemplates::class);

    expect($templates->definition('empty', 0, 0)['assets'])->toBe([])
        ->and($templates->definition('empty', 0, 0))->not->toHaveKeys(['tariff', 'climate'])
        ->and(array_column($templates->definition('power', 0, 0)['assets'], 'kind'))->toBe(['site', 'weather', 'grid', 'bus']);
});

it('takes solar time when no offset is given', function (float $lon, float $offset): void {
    expect(resolve(TwinTemplates::class)->definition('empty', 0, $lon)['utcOffsetHours'])->toBe($offset);
})->with([[174.76, 12.0], [-122.4, -8.0], [0.1, 0.0]]);

it('refuses a template it does not have', function (): void {
    resolve(TwinTemplates::class)->definition('volcano', 0, 0);
})->throws(InvalidArgumentException::class, 'Unknown twin template volcano');

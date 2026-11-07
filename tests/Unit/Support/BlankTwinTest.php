<?php

declare(strict_types=1);

use App\Support\BlankTwin;
use App\Support\TwinDefinitionValidator;

it('builds the definition the viewer tests run, and the validator accepts it', function (): void {
    $definition = resolve(BlankTwin::class)->definition(-36.85, 174.76, 12);

    expect($definition)->toBe(json_decode((string) file_get_contents(base_path('tests/fixtures/blank-twin.json')), true))
        ->and(resolve(TwinDefinitionValidator::class)->errors($definition))->toBe([]);
});

it('takes solar time when no offset is given', function (float $lon, float $offset): void {
    expect(resolve(BlankTwin::class)->definition(0, $lon)['utcOffsetHours'])->toBe($offset);
})->with([[174.76, 12.0], [-122.4, -8.0], [0.1, 0.0]]);

<?php

declare(strict_types=1);

use App\Support\SampleTwins;

it('lists the bundled samples', function (): void {
    expect(resolve(SampleTwins::class)->all())->toBe([
        'northshore' => 'Northshore Cold Chain Hub',
        'harbour-depot' => 'Harbour Depot',
    ]);
});

it('reads a sample definition', function (): void {
    expect(resolve(SampleTwins::class)->definition('northshore'))->toHaveKey('id', 'northshore');
});

it('refuses an unknown sample rather than reading an arbitrary file', function (): void {
    resolve(SampleTwins::class)->definition('../../.env');
})->throws(InvalidArgumentException::class);

<?php

declare(strict_types=1);

use App\Support\Expression;

/**
 * @return list<array{src: string, value?: int|float|null, error?: string}>
 */
function expressionCases(): array
{
    /** @var array{cases: list<array{src: string, value?: int|float|null, error?: string}>} $fixtures */
    $fixtures = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/expression-cases.json'), true, flags: JSON_THROW_ON_ERROR);

    return $fixtures['cases'];
}

it('parses like the viewer does', function (string $src, ?string $error): void {
    expect(Expression::error($src))->toBe($error);
})->with(fn (): array => array_map(
    fn (array $case): array => [$case['src'], $case['error'] ?? null],
    expressionCases(),
));

it('refuses anything that is not text, or is too long', function (): void {
    expect(Expression::error(3))->toBe('must be text')
        ->and(Expression::error(str_repeat('1+', Expression::MAX_LENGTH)))->toBe('longer than 500 characters');
});

it('lists the names and references an expression reads', function (): void {
    expect(Expression::references('!idle && -levelPct < max({fire-tank.levelPct}, 1) ? flowLps : levelPct'))->toBe([
        'names' => ['idle', 'levelPct', 'flowLps'],
        'refs' => [['asset' => 'fire-tank', 'metric' => 'levelPct']],
    ]);
});

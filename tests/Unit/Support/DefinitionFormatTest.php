<?php

declare(strict_types=1);

use App\Support\DefinitionFormat;

/**
 * @return array<string, array{mixed, mixed}>
 */
function definitionMigrations(): array
{
    /** @var array{cases: list<array{name: string, from: mixed, to: mixed}>} $fixtures */
    $fixtures = json_decode((string) file_get_contents(base_path('tests/fixtures/definition-migrations.json')), true);

    return collect($fixtures['cases'])->mapWithKeys(fn (array $case): array => [$case['name'] => [$case['from'], $case['to']]])->all();
}

it('brings definitions up to the current format, as the viewer does', function (mixed $from, mixed $to): void {
    expect(DefinitionFormat::migrate($from))->toBe($to);
})->with(definitionMigrations());

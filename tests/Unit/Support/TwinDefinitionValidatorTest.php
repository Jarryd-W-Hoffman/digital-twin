<?php

declare(strict_types=1);

use App\Support\TwinDefinitionValidator;
use Illuminate\Support\Arr;

/**
 * @return array<string, array{mixed, list<string>}>
 */
function definitionCases(): array
{
    /** @var array{base: string, cases: list<array{name: string, replace?: mixed, ops?: list<array<string, mixed>>, errors: list<string>}>} $fixtures */
    $fixtures = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/definition-cases.json'), true, flags: JSON_THROW_ON_ERROR);

    $cases = [];

    foreach ($fixtures['cases'] as $case) {
        if (array_key_exists('replace', $case)) {
            $cases[$case['name']] = [$case['replace'], $case['errors']];

            continue;
        }

        /** @var array<string, mixed> $definition */
        $definition = json_decode((string) file_get_contents(__DIR__.'/../../../public/samples/'.$fixtures['base'].'.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($case['ops'] ?? [] as $op) {
            if (array_key_exists('set', $op)) {
                Arr::set($definition, (string) $op['set'], $op['value']);
            } elseif (array_key_exists('unset', $op)) {
                Arr::forget($definition, (string) $op['unset']);
            } else {
                $list = Arr::get($definition, (string) $op['push']);
                assert(is_array($list));
                $list[] = $op['value'];
                Arr::set($definition, (string) $op['push'], $list);
            }
        }

        $cases[$case['name']] = [$definition, $case['errors']];
    }

    return $cases;
}

it('reports the same problems as the viewer', function (mixed $definition, array $errors): void {
    expect(new TwinDefinitionValidator()->errors($definition))->toBe($errors);
})->with(definitionCases());

it('describes non-string values the way JavaScript prints them', function (mixed $kind, string $printed): void {
    $definition = definitionCases()['the sample is valid'][0];
    assert(is_array($definition));
    Arr::set($definition, 'assets.1.kind', $kind);

    expect(new TwinDefinitionValidator()->errors($definition))->toBe(['wx: unknown kind '.$printed]);
})->with([
    [null, 'null'],
    [true, 'true'],
    [false, 'false'],
    [3, '3'],
    [1.5, '1.5'],
    [['a', 'b'], 'a,b'],
    [['x' => 1], '[object Object]'],
]);

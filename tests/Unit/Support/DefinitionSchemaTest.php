<?php

declare(strict_types=1);

use App\Support\DefinitionSchema;

it('orders list items by index, before fields, and fields by name', function (): void {
    $schema = new DefinitionSchema();
    $paths = [['zones', 'id'], ['assets', 10], ['assets'], ['assets', 'x'], ['assets', 2], ['assets', 2, 'kind']];
    usort($paths, $schema->comparePaths(...));

    expect($paths)->toBe([['assets'], ['assets', 2], ['assets', 2, 'kind'], ['assets', 10], ['assets', 'x'], ['zones', 'id']]);
});

it('prints values the way JavaScript does', function (mixed $value, string $shown): void {
    expect(new DefinitionSchema()->show($value))->toBe($shown);
})->with([
    [null, 'null'],
    [true, 'true'],
    [false, 'false'],
    [1.5, '1.5'],
    [[1, 'a'], '1,a'],
    [['a' => 1], '[object Object]'],
]);

it('reads an empty array as an object where the schema wants one', function (): void {
    expect(new DefinitionSchema()->errors(['tariff' => []]))->not->toContain('tariff must be an object');
});

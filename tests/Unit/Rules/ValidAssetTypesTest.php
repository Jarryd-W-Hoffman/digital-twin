<?php

declare(strict_types=1);

use App\Rules\ValidAssetTypes;
use App\Support\SampleTwins;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

function typesFile(mixed $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent('types.json', is_string($contents) ? $contents : (string) json_encode($contents));
}

function typesError(mixed $contents): string
{
    return Validator::make(['file' => typesFile($contents)], ['file' => new ValidAssetTypes()])->errors()->first('file');
}

it('takes one type, a list of them, or the types of a definition', function (mixed $decoded, int $count): void {
    expect(Validator::make(['file' => typesFile($decoded)], ['file' => new ValidAssetTypes()])->passes())->toBeTrue()
        ->and(ValidAssetTypes::from($decoded))->toHaveCount($count);
})->with([
    'one' => fn (): array => [northshoreType('water-tank'), 1],
    'a list' => fn (): array => [[northshoreType('water-tank'), northshoreType('jockey-pump')], 2],
    'a definition' => fn (): array => [resolve(SampleTwins::class)->definition('northshore'), 2],
]);

it('reports every problem in every type, once', function (): void {
    $broken = [...northshoreType('water-tank'), 'primary' => 'nope'];

    expect(explode("\n", typesError([$broken, $broken, 'pump'])))->toBe([
        'Type water-tank: primary must be one of its metrics',
        'Duplicate type id water-tank',
        'A type must be a JSON object',
    ]);
});

it('refuses files with no types, too many, or no JSON', function (mixed $contents, string $message): void {
    expect(typesError($contents))->toBe($message);
})->with([
    [[], 'The file has no types.'],
    [['types' => []], 'The file has no types.'],
    [array_fill(0, 51, ['id' => 'x']), 'The file has more than 50 types.'],
    ['{nope', 'The file is not valid JSON.'],
    ['"pump"', 'A type must be a JSON object'],
]);

it('reads nothing that is not a file', function (): void {
    expect(Validator::make(['file' => 'text'], ['file' => new ValidAssetTypes()])->errors()->first('file'))->toBe('The file is not valid JSON.');
});

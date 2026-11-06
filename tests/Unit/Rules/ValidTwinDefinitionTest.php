<?php

declare(strict_types=1);

use App\Rules\ValidTwinDefinition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

it('passes a sound definition in any of its forms', function (mixed $value): void {
    expect(Validator::make(['d' => $value], ['d' => new ValidTwinDefinition()])->passes())->toBeTrue();
})->with([
    'decoded' => fn (): mixed => json_decode((string) file_get_contents(public_path('samples/harbour-depot.json')), true),
    'string' => fn (): string => (string) file_get_contents(public_path('samples/harbour-depot.json')),
    'file' => fn (): UploadedFile => UploadedFile::fake()->createWithContent('t.json', (string) file_get_contents(public_path('samples/harbour-depot.json'))),
]);

it('reports every problem', function (): void {
    $validator = Validator::make(['d' => ['assets' => []]], ['d' => new ValidTwinDefinition()]);

    expect(explode("\n", $validator->errors()->first('d')))->toContain('Missing id', 'Missing tariff', 'zones must be an array');
});

it('rejects text that is not JSON', function (): void {
    $validator = Validator::make(['d' => '{nope'], ['d' => new ValidTwinDefinition()]);

    expect($validator->errors()->first('d'))->toBe('The d is not valid JSON.');
});

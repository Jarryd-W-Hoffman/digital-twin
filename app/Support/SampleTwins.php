<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * The sample definitions shipped in public/samples, offered as starting points.
 */
final readonly class SampleTwins
{
    /**
     * @return array<string, string> Sample id to display name.
     */
    public function all(): array
    {
        /** @var list<array{id: string, name: string}> $index */
        $index = File::json(public_path('samples/index.json'));

        return collect($index)->mapWithKeys(fn (array $sample): array => [$sample['id'] => $sample['name']])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(string $id): array
    {
        throw_unless(array_key_exists($id, $this->all()), InvalidArgumentException::class, 'Unknown sample twin '.$id);

        /** @var array<string, mixed> */
        return File::json(public_path(sprintf('samples/%s.json', $id)));
    }
}

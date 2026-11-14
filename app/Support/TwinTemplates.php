<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * The templates in resources/templates a new twin starts from at a chosen
 * place: an empty site, or one laid out for a kind of work, such as a factory
 * line, a mine or a survey. Only "Site power" models electricity.
 */
final readonly class TwinTemplates
{
    /**
     * @return array<string, array{name: string, description: string}> Template id to its name and description.
     */
    public function all(): array
    {
        /** @var list<array{id: string, name: string, description: string}> $index */
        $index = File::json(resource_path('templates/index.json'));

        return collect($index)->mapWithKeys(fn (array $template): array => [
            $template['id'] => ['name' => $template['name'], 'description' => $template['description']],
        ])->all();
    }

    /**
     * The template placed at a latitude and longitude, on solar time unless an offset is given.
     *
     * @return array<string, mixed>
     */
    public function definition(string $id, float $lat, float $lon, ?float $utcOffsetHours = null): array
    {
        throw_unless(array_key_exists($id, $this->all()), InvalidArgumentException::class, 'Unknown twin template '.$id);

        /** @var array<string, mixed> $template */
        $template = File::json(resource_path(sprintf('templates/%s.json', $id)));

        return [
            ...$template,
            'origin' => ['lon' => $lon, 'lat' => $lat],
            'utcOffsetHours' => $utcOffsetHours ?? round($lon / 15),
        ];
    }
}

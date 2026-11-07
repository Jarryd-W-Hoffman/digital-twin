<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AssetType;
use App\Models\Twin;
use App\Models\TwinVersion;
use App\Models\User;
use App\Support\TwinDefinitionValidator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class AddTypeToTwin
{
    public function __construct(private SaveTwinVersion $versions, private TwinDefinitionValidator $validator) {}

    /**
     * Copies a library type into the twin as a new version, replacing the
     * twin's type with the same id. The twin must still be valid with it: a
     * type that reads another asset needs that asset in the twin. Null when
     * the twin already has this type as the library has it.
     */
    public function handle(Twin $twin, ?User $user, AssetType $type): ?TwinVersion
    {
        throw_if($type->organisation_id !== $twin->organisation_id, InvalidArgumentException::class, 'The type belongs to another organisation.');

        $definition = $twin->latestVersion()->firstOrFail()->definition;
        $types = is_array($definition['types'] ?? null) ? array_values($definition['types']) : [];
        $index = array_find_key($types, fn (mixed $current): bool => is_array($current) && ($current['id'] ?? null) === $type->slug);

        if ($index !== null && $type->matches($types[$index])) {
            return null;
        }

        $types[$index ?? count($types)] = $type->definition;
        $definition['types'] = $types;
        $errors = $this->validator->errors($definition);

        throw_unless($errors === [], ValidationException::withMessages([
            'type' => implode("\n", [__(':name can’t be added to this twin:', ['name' => $type->name]), ...$errors]),
        ]));

        $note = $index === null ? 'Added type :name from the library' : 'Updated type :name from the library';

        return $this->versions->handle($twin, $user, $definition, __($note, ['name' => $type->name]));
    }
}

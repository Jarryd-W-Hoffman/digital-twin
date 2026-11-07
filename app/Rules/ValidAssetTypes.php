<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\TwinDefinitionValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Accepts a JSON file of types for the library: one type, a list of them,
 * or anything with a `types` list (such as a twin definition). Every problem
 * is reported, one per line, as with definitions.
 */
final readonly class ValidAssetTypes implements ValidationRule
{
    public const int MAX = 50;

    /**
     * The types a decoded file holds.
     *
     * @return list<mixed>
     */
    public static function from(mixed $decoded): array
    {
        if (is_array($decoded) && array_key_exists('types', $decoded)) {
            $decoded = $decoded['types'];
        } elseif (is_array($decoded) && ! array_is_list($decoded)) {
            return [$decoded];
        }

        return is_array($decoded) ? array_values($decoded) : [$decoded];
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $decoded = json_decode($value instanceof UploadedFile ? (string) $value->get() : '', true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $fail('The :attribute is not valid JSON.');

            return;
        }

        $types = self::from($decoded);

        if ($types === []) {
            $fail('The :attribute has no types.');

            return;
        }

        if (count($types) > self::MAX) {
            $fail(sprintf('The :attribute has more than %d types.', self::MAX));

            return;
        }

        $validator = new TwinDefinitionValidator();
        $errors = [];
        $ids = [];

        foreach ($types as $type) {
            $errors = [...$errors, ...$validator->type($type)['errors']];
            $id = is_array($type) ? ($type['id'] ?? null) : null;

            if (is_string($id) && in_array($id, $ids, true)) {
                $errors[] = 'Duplicate type id '.$id;
            }

            $ids[] = $id;
        }

        if ($errors !== []) {
            $fail(implode("\n", array_values(array_unique($errors))));
        }
    }
}

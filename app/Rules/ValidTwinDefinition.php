<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\TwinDefinitionValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Accepts a definition as decoded JSON, a JSON string or an uploaded JSON file,
 * and reports every problem the viewer would, one per line in a single
 * message, since Inertia passes only the first message for each field.
 */
final readonly class ValidTwinDefinition implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof UploadedFile) {
            $value = (string) $value->get();
        }

        $definition = is_string($value) ? json_decode($value, true) : $value;

        if (is_string($value) && json_last_error() !== JSON_ERROR_NONE) {
            $fail('The :attribute is not valid JSON.');

            return;
        }

        $errors = new TwinDefinitionValidator()->errors($definition);

        if ($errors !== []) {
            $fail(implode("\n", $errors));
        }
    }
}

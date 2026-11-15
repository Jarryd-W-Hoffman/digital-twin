<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Twin definitions say which format they're in (`format`, 1 when absent), so
 * one saved by an older Meridian can be brought up to date rather than break.
 * resources/js/twin/core/format.ts does the same, and
 * tests/fixtures/definition-migrations.json checks both.
 */
final class DefinitionFormat
{
    /** The format this Meridian writes, and the newest it reads. */
    public const int CURRENT = 2;

    /**
     * The definition in the current format. Anything that isn't an object, or
     * says it's in a format this Meridian doesn't know, is left as it is for
     * the validator to refuse.
     */
    public static function migrate(mixed $definition): mixed
    {
        if (! is_array($definition) || ($definition !== [] && array_is_list($definition))) {
            return $definition;
        }

        $format = $definition['format'] ?? 1;

        if (! is_int($format) || $format < 1 || $format > self::CURRENT) {
            return $definition;
        }

        if ($format < 2) {
            $definition = self::terrainsAsAList($definition);
        }

        unset($definition['format']);

        return ['format' => self::CURRENT, ...$definition];
    }

    /**
     * 1 to 2: a twin can stand on several terrains, one at a time, so its
     * `terrain` becomes the first of its `terrains`.
     *
     * @param  array<mixed>  $definition
     * @return array<mixed>
     */
    private static function terrainsAsAList(array $definition): array
    {
        if (array_key_exists('terrain', $definition)) {
            $definition['terrains'] = [$definition['terrain']];
            unset($definition['terrain']);
        }

        return $definition;
    }
}

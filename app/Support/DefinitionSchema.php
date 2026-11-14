<?php

declare(strict_types=1);

namespace App\Support;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use stdClass;

/**
 * The structural half of a definition's checks, from the schema the viewer
 * also uses (resources/js/twin/core/definition.schema.json). Mirrors
 * resources/js/twin/core/schema.ts: each failed check reports its
 * x-message, ordered by where it is in the definition.
 */
final readonly class DefinitionSchema
{
    /**
     * Keywords that fail on their own; the rest only collect their children's failures.
     */
    private const array ASSERTIONS = [
        'type', 'required', 'enum', 'const', 'minimum', 'maximum', 'exclusiveMinimum',
        'exclusiveMaximum', 'minLength', 'pattern', 'minItems', 'maxItems', 'anyOf', 'not',
    ];

    /**
     * Stands for a field that isn't there, which reads as "undefined" like in JavaScript.
     */
    private const string MISSING = "\0missing";

    /**
     * The schema as arrays, for finding messages.
     *
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        /** @var array<string, array<string, mixed>> $cache */
        static $cache = [];

        if ($cache === []) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) file_get_contents(resource_path('js/twin/core/definition.schema.json')), true, flags: JSON_THROW_ON_ERROR);
            $cache['schema'] = $decoded;
        }

        return $cache['schema'];
    }

    /**
     * Every structural problem, each reported once. With $only, just that
     * part of the definition is checked (the library checks types alone).
     *
     * @return list<string>
     */
    public function errors(mixed $definition, ?string $only = null): array
    {
        $schema = self::document();

        if ($only !== null) {
            $schema = ['$schema' => $schema['$schema'], 'x-message' => $schema['x-message'], 'properties' => [$only => $this->valueAt($schema, ['properties', $only])]];
        }

        $validator = new Validator();
        $validator->setMaxErrors(100_000)->setStopAtFirstError(false);
        $result = $validator->validate($this->toJson($definition, [$schema]), $this->toSchema($schema));
        $error = $result->error();

        if (! $error instanceof ValidationError) {
            return [];
        }

        /** @var list<array{list<string|int>, string}> $found */
        $found = [];
        $this->collect($error, $definition, $schema, $found);
        usort($found, fn (array $a, array $b): int => $this->comparePaths($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

        return array_values(array_unique(array_column($found, 1)));
    }

    /**
     * @param  list<string|int>  $a
     * @param  list<string|int>  $b
     */
    public function comparePaths(array $a, array $b): int
    {
        foreach (array_keys(array_slice($a, 0, min(count($a), count($b)))) as $i) {
            $x = $a[$i];
            $y = $b[$i];

            if ($x === $y) {
                continue;
            }

            if (is_int($x) && is_int($y)) {
                return $x <=> $y;
            }

            if (is_int($x)) {
                return -1;
            }

            if (is_int($y)) {
                return 1;
            }

            return strcmp($x, $y) < 0 ? -1 : 1;
        }

        return count($a) <=> count($b);
    }

    /**
     * Prints a value the way JavaScript's String() would.
     */
    public function show(mixed $value): string
    {
        return match (true) {
            $value === self::MISSING => 'undefined',
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_array($value) && array_is_list($value) => implode(',', array_map($this->show(...), $value)),
            default => '[object Object]',
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<array{list<string|int>, string}>  $found
     */
    private function collect(ValidationError $error, mixed $definition, array $schema, array &$found): void
    {
        if (in_array($error->keyword(), self::ASSERTIONS, true)) {
            /** @var list<string|int> $schemaPath */
            $schemaPath = $error->schema()->info()->path();
            /** @var list<string|int> $path */
            $path = $error->data()->fullPath();

            if ($error->keyword() === 'required') {
                /** @var list<string> $missing */
                $missing = $error->args()['missing'] ?? [];

                foreach ($missing as $field) {
                    $child = $this->valueAt($schema, [...$schemaPath, 'properties', $field, 'x-message']);
                    $at = [...$path, $field];
                    $found[] = [$at, $this->format(is_string($child) ? $child : $this->messageFor($schema, $schemaPath, 'required'), $definition, $at)];
                }
            } else {
                $found[] = [$path, $this->format($this->messageFor($schema, $schemaPath, $error->keyword()), $definition, $path)];
            }
        }

        foreach ($error->subErrors() as $sub) {
            if ($sub instanceof ValidationError) {
                $this->collect($sub, $definition, $schema, $found);
            }
        }
    }

    /**
     * What's at a path of keys, or null.
     *
     * @param  array<array-key, mixed>  $root
     * @param  list<string|int>  $path
     */
    private function valueAt(array $root, array $path): mixed
    {
        $node = $root;

        foreach ($path as $key) {
            $node = is_array($node) ? ($node[$key] ?? null) : null;
        }

        return $node;
    }

    /**
     * A failing check's message: its own, or the nearest one above it.
     * A check of one part (see errors()) keeps the root's message.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string|int>  $path
     */
    private function messageFor(array $schema, array $path, string $keyword): string
    {
        $own = $this->valueAt($schema, [...$path, 'x-messages', $keyword]);

        if (is_string($own)) {
            return $own;
        }

        // The root has a message, so the walk always ends with one.
        for ($i = count($path); ; $i--) {
            $message = $this->valueAt($schema, [...array_slice($path, 0, $i), 'x-message']);

            if (is_string($message)) {
                return $message;
            }
        }
    }

    /**
     * Fills {N:field} with a field of the Nth list item on the path, and {value} with what's there.
     *
     * @param  list<string|int>  $path
     */
    private function format(string $message, mixed $definition, array $path): string
    {
        $items = [];
        $value = $definition;

        foreach ($path as $segment) {
            $parent = $value;
            $value = is_array($parent) && array_key_exists($segment, $parent) ? $parent[$segment] : self::MISSING;

            if (is_array($parent) && array_is_list($parent)) {
                $items[] = $value;
            }
        }

        $message = (string) preg_replace_callback('/\{(\d+):(\w+)\}/', function (array $match) use ($items): string {
            $item = $items[(int) $match[1]] ?? null;
            $text = is_array($item) ? ($item[$match[2]] ?? null) : null;

            if (is_int($text) || is_float($text)) {
                return (string) $text;
            }

            return is_string($text) && $text !== '' ? $text : '?';
        }, $message);

        return str_replace('{value}', $this->show($value), $message);
    }

    /**
     * PHP decodes {} and [] alike, so an empty array becomes an object
     * wherever the schema expects one.
     *
     * @param  list<array<array-key, mixed>>  $nodes
     */
    private function toJson(mixed $value, array $nodes): mixed
    {
        $nodes = $this->expand($nodes);

        if (! is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return array_any($nodes, fn (array $node): bool => ($node['type'] ?? null) === 'object') ? new stdClass() : [];
        }

        if (array_is_list($value)) {
            $items = array_values(array_filter(array_map(fn (array $node): mixed => $this->valueAt($node, ['items']), $nodes), is_array(...)));

            return array_map(fn (mixed $item): mixed => $this->toJson($item, $items), $value);
        }

        $object = new stdClass();

        foreach ($value as $key => $item) {
            $children = array_values(array_filter(array_map(fn (array $node): mixed => $this->valueAt($node, ['properties', $key]), $nodes), is_array(...)));
            $object->{$key} = $this->toJson($item, $children);
        }

        return $object;
    }

    /**
     * The nodes that apply at one place: each node and its conditional parts.
     *
     * @param  list<array<array-key, mixed>>  $nodes
     * @return list<array<array-key, mixed>>
     */
    private function expand(array $nodes): array
    {
        $all = [];

        foreach ($nodes as $node) {
            $all[] = $node;
            $parts = [$node['then'] ?? null, $node['else'] ?? null, ...(is_array($node['allOf'] ?? null) ? $node['allOf'] : [])];
            $all = [...$all, ...$this->expand(array_values(array_filter($parts, is_array(...))))];
        }

        return $all;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function toSchema(array $schema): stdClass
    {
        $object = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);
        assert($object instanceof stdClass);

        return $object;
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Mirrors resources/js/twin/core/validate.ts message for message, so a twin the
 * control plane accepts is one the viewer and gateway can run. Both are held to
 * tests/fixtures/definition-cases.json.
 */
final readonly class TwinDefinitionValidator
{
    private const array KINDS = [
        'site', 'weather', 'grid', 'solar', 'battery', 'genset', 'bus',
        'chiller', 'coldstore', 'building', 'charger', 'vehicle', 'custom',
    ];

    private const array SHAPES = ['box', 'cylinder'];

    private const string ID = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /**
     * @return list<string>
     */
    public function errors(mixed $definition): array
    {
        if (! $this->isObject($definition)) {
            return ['Definition must be a JSON object'];
        }

        /** @var array<string, mixed> $definition */
        $errors = [];

        foreach (['id', 'name'] as $field) {
            if (! $this->isText($definition[$field] ?? null)) {
                $errors[] = 'Missing '.$field;
            }
        }

        $origin = $this->object($definition['origin'] ?? null);
        $lon = $origin['lon'] ?? null;
        $lat = $origin['lat'] ?? null;

        if (! $this->isNumber($lon) || $lon < -180 || $lon > 180) {
            $errors[] = 'origin.lon must be -180..180';
        }

        if (! $this->isNumber($lat) || $lat < -90 || $lat > 90) {
            $errors[] = 'origin.lat must be -90..90';
        }

        foreach (['tariff', 'climate', 'camera'] as $field) {
            if (! $this->isObject($definition[$field] ?? null)) {
                $errors[] = 'Missing '.$field;
            }
        }

        if (! $this->isList($definition['assets'] ?? null)) {
            return [...$errors, 'assets must be an array'];
        }

        foreach (['zones', 'routes', 'rules', 'scenarios'] as $field) {
            if (! $this->isList($definition[$field] ?? null)) {
                $errors[] = $field.' must be an array';
            }
        }

        foreach (['types', 'tilesets', 'models'] as $field) {
            if (array_key_exists($field, $definition) && ! $this->isList($definition[$field])) {
                $errors[] = $field.' must be an array';
            }
        }

        $types = $this->checkTypes($errors, $definition);

        /** @var array<string, array<string, mixed>> $byId */
        $byId = [];

        foreach ($this->items($definition['assets']) as $asset) {
            if (! $this->isObject($asset) || ! $this->isText($asset['id'] ?? null)) {
                $errors[] = 'An asset has no id';

                continue;
            }

            /** @var array<string, mixed> $asset */
            /** @var string $id */
            $id = $asset['id'];

            if (array_key_exists($id, $byId)) {
                $errors[] = 'Duplicate asset id '.$id;
            }

            $byId[$id] = $asset;

            if (! in_array($asset['kind'] ?? null, self::KINDS, true)) {
                $errors[] = sprintf('%s: unknown kind %s', $id, $this->describe($asset, 'kind'));
            }
        }

        $zones = $this->ids($definition['zones'] ?? null);
        $routes = $this->ids($definition['routes'] ?? null);

        foreach ($byId as $id => $asset) {
            $id = (string) $id;
            $kind = $asset['kind'] ?? null;

            if (! array_key_exists('footprint', $asset) && ! array_key_exists('position', $asset) && $kind !== 'vehicle' && $kind !== 'site') {
                $errors[] = $id.': needs a footprint or position';
            }

            if (array_key_exists('footprint', $asset) && ! $this->isPolygon($asset['footprint'])) {
                $errors[] = $id.': footprint needs 3+ points';
            }

            if (array_key_exists('position', $asset) && ! $this->isPoint($asset['position'])) {
                $errors[] = $id.': position must be [east, north]';
            }

            if (array_key_exists('zone', $asset) && $asset['zone'] !== '' && ! in_array($asset['zone'], $zones, true)) {
                $errors[] = sprintf('%s: unknown zone %s', $id, $this->describe($asset, 'zone'));
            }

            if (array_key_exists('fedBy', $asset) && ! array_key_exists($this->describe($asset, 'fedBy'), $byId)) {
                $errors[] = sprintf('%s: fedBy unknown asset %s', $id, $this->describe($asset, 'fedBy'));
            }

            foreach ($this->items($asset['cools'] ?? null) as $cooled) {
                $name = $this->describeValue($cooled);

                if (($byId[$name]['kind'] ?? null) !== 'coldstore') {
                    $errors[] = sprintf('%s: cools %s, which is not a cold store', $id, $name);
                }
            }

            if ($kind === 'vehicle' && ! in_array($asset['route'] ?? null, $routes, true)) {
                $errors[] = sprintf('%s: unknown route %s', $id, $this->describe($asset, 'route'));
            }

            if ($kind === 'custom' && ! array_key_exists($this->describe($asset, 'type'), $types)) {
                $errors[] = sprintf('%s: unknown type %s', $id, $this->describe($asset, 'type'));
            }

            $seen = [$id => true];

            for ($cursor = $asset['fedBy'] ?? null; is_string($cursor); $cursor = $byId[$cursor]['fedBy'] ?? null) {
                if (array_key_exists($cursor, $seen)) {
                    $errors[] = $id.': fedBy forms a cycle';

                    break;
                }

                $seen[$cursor] = true;
            }
        }

        $mains = array_filter($byId, fn (array $asset): bool => ($asset['kind'] ?? null) === 'bus' && ! array_key_exists('fedBy', $asset));

        if (count($mains) !== 1) {
            $errors[] = 'Twin needs exactly one main bus (a bus with no fedBy)';
        }

        foreach ($this->objects($definition['routes'] ?? null) as $route) {
            $name = $this->label($route['id'] ?? null);
            $length = count($this->items($route['path'] ?? null));

            if (! $this->isList($route['path'] ?? null) || $length < 2) {
                $errors[] = sprintf('Route %s: path needs 2+ points', $name);
            }

            foreach ($this->objects($route['stops'] ?? null) as $stop) {
                $at = $stop['at'] ?? null;

                if (! is_int($at) || $at < 0 || $at >= $length) {
                    $errors[] = sprintf('Route %s: stop at %s is off the path', $name, $this->describe($stop, 'at'));
                }

                $dwell = $stop['dwellS'] ?? null;

                if (! $this->isNumber($dwell) || $dwell <= 0) {
                    $errors[] = sprintf('Route %s: stop dwell must be positive', $name);
                }
            }
        }

        foreach ($this->objects($definition['rules'] ?? null) as $rule) {
            $name = $this->label($rule['id'] ?? null);

            if (! array_key_exists('kind', $rule) && ! array_key_exists('asset', $rule)) {
                $errors[] = sprintf('Rule %s: needs a kind or an asset', $name);
            }

            if (array_key_exists('asset', $rule) && ! array_key_exists($this->describe($rule, 'asset'), $byId)) {
                $errors[] = sprintf('Rule %s: unknown asset %s', $name, $this->describe($rule, 'asset'));
            }

            if (! in_array($rule['op'] ?? null, ['gt', 'lt'], true)) {
                $errors[] = sprintf('Rule %s: op must be gt or lt', $name);
            }
        }

        foreach ($this->objects($definition['scenarios'] ?? null) as $scenario) {
            foreach ($this->objects($scenario['faults'] ?? null) as $fault) {
                if (array_key_exists('target', $fault) && ! array_key_exists($this->describe($fault, 'target'), $byId)) {
                    $errors[] = sprintf('Scenario %s: unknown target %s', $this->label($scenario['id'] ?? null), $this->describe($fault, 'target'));
                }
            }
        }

        $this->checkLayers($errors, $definition);

        return $errors;
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $definition
     * @return array<string, true>
     */
    private function checkTypes(array &$errors, array $definition): array
    {
        $types = [];

        foreach ($this->items($definition['types'] ?? null) as $type) {
            $id = $this->isObject($type) ? ($type['id'] ?? null) : null;

            if (! is_string($id) || preg_match(self::ID, $id) !== 1) {
                $errors[] = 'A type needs an id of lowercase letters, digits, - or _';

                continue;
            }

            /** @var array<string, mixed> $type */
            if (array_key_exists($id, $types)) {
                $errors[] = 'Duplicate type id '.$id;
            }

            $types[$id] = true;

            if (! $this->isText($type['name'] ?? null)) {
                $errors[] = sprintf('Type %s: needs a name', $id);
            }

            $metrics = $this->items($type['metrics'] ?? null);

            if ($metrics === []) {
                $errors[] = sprintf('Type %s: needs at least one metric', $id);
            }

            $keys = [];

            foreach ($metrics as $metric) {
                $key = $this->isObject($metric) ? ($metric['key'] ?? null) : null;

                if (! is_string($key) || preg_match(self::ID, $key) !== 1 || $key === 'state') {
                    $errors[] = sprintf('Type %s: metric keys must be lowercase identifiers other than state', $id);

                    continue;
                }

                /** @var array<string, mixed> $metric */
                if (in_array($key, $keys, true)) {
                    $errors[] = sprintf('Type %s: duplicate metric %s', $id, $key);
                }

                $keys[] = $key;

                if (! is_string($metric['unit'] ?? null)) {
                    $errors[] = sprintf('Type %s: metric %s needs a unit', $id, $key);
                }

                if (array_key_exists('default', $metric) && ! $this->isNumber($metric['default'])) {
                    $errors[] = sprintf('Type %s: metric %s default must be a number', $id, $key);
                }
            }

            if (! in_array($type['primary'] ?? null, $keys, true)) {
                $errors[] = sprintf('Type %s: primary must be one of its metrics', $id);
            }

            $visual = $this->object($type['visual'] ?? null);
            $color = $visual['color'] ?? null;

            if (! is_string($color) || preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
                $errors[] = sprintf('Type %s: visual.color must be a #rrggbb colour', $id);
            }

            if (! in_array($visual['shape'] ?? null, self::SHAPES, true)) {
                $errors[] = sprintf('Type %s: visual.shape must be box or cylinder', $id);
            }
        }

        return $types;
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $definition
     */
    private function checkLayers(array &$errors, array $definition): void
    {
        $layers = [];

        foreach (['tilesets' => 'Tileset', 'models' => 'Model'] as $field => $noun) {
            foreach ($this->items($definition[$field] ?? null) as $layer) {
                $id = $this->isObject($layer) ? ($layer['id'] ?? null) : null;

                if (! $this->isText($id)) {
                    $errors[] = sprintf('A %s has no id', mb_strtolower($noun));

                    continue;
                }

                /** @var string $id */
                /** @var array<string, mixed> $layer */
                if (array_key_exists($id, $layers)) {
                    $errors[] = 'Duplicate layer id '.$id;
                }

                $layers[$id] = true;

                if (! $this->isText($layer['url'] ?? null)) {
                    $errors[] = sprintf('%s %s: needs a url', $noun, $id);
                }

                if ($field === 'models' || array_key_exists('placement', $layer)) {
                    $this->checkPlacement($errors, sprintf('%s %s', $noun, $id), $layer['placement'] ?? null);
                }
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function checkPlacement(array &$errors, string $owner, mixed $placement): void
    {
        if (! $this->isObject($placement)) {
            $errors[] = $owner.': placement must be an object';

            return;
        }

        foreach (['east', 'north', 'up', 'headingDeg'] as $field) {
            if (! $this->isNumber($placement[$field] ?? null)) {
                $errors[] = sprintf('%s: placement.%s must be a number', $owner, $field);
            }
        }

        $scale = $placement['scale'] ?? null;

        if (! $this->isNumber($scale) || $scale <= 0) {
            $errors[] = $owner.': placement.scale must be positive';
        }
    }

    /**
     * A decoded JSON object. An empty object decodes to [] so it counts too.
     *
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /**
     * @phpstan-assert-if-true list<mixed> $value
     */
    private function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * @phpstan-assert-if-true int|float $value
     */
    private function isNumber(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    private function isText(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private function isPoint(mixed $value): bool
    {
        return $this->isList($value) && count($value) === 2 && $this->isNumber($value[0]) && $this->isNumber($value[1]);
    }

    private function isPolygon(mixed $value): bool
    {
        $points = $this->items($value);

        return $this->isList($value) && count($points) >= 3 && array_all($points, $this->isPoint(...));
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value): array
    {
        return $this->isObject($value) ? $value : [];
    }

    /**
     * @return list<mixed>
     */
    private function items(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function objects(mixed $value): array
    {
        /** @var list<array<string, mixed>> */
        return array_values(array_filter($this->items($value), $this->isObject(...)));
    }

    /**
     * @return list<mixed>
     */
    private function ids(mixed $value): array
    {
        return array_map(fn (array $item): mixed => $item['id'] ?? null, $this->objects($value));
    }

    private function label(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : '?';
    }

    /**
     * How JavaScript's String() prints a field, including one that is absent.
     *
     * @param  array<string, mixed>  $object
     */
    private function describe(array $object, string $key): string
    {
        return array_key_exists($key, $object) ? $this->describeValue($object[$key]) : 'undefined';
    }

    private function describeValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $this->isList($value) => implode(',', array_map($this->describeValue(...), $value)),
            default => '[object Object]',
        };
    }
}

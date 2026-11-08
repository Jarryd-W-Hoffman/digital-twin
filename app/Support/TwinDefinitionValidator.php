<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Mirrors resources/js/twin/core/validate.ts message for message, so a twin the
 * control plane accepts is one the viewer and gateway can run. Both are held to
 * tests/fixtures/definition-cases.json. Checks of shape live in the schema both
 * read (DefinitionSchema); these are the ones that need the whole definition:
 * references, duplicates, cycles and expressions.
 */
final readonly class TwinDefinitionValidator
{
    /**
     * Names every custom type's expressions can read besides its own.
     */
    private const array TYPE_BUILTINS = ['dt', 'hour', 'ambientC', 'powered'];

    private const string ID = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    private const string METRIC_KEY = '/^[a-z][A-Za-z0-9_]{0,63}$/';

    public function __construct(private DefinitionSchema $schema = new DefinitionSchema()) {}

    /**
     * @return list<string>
     */
    public function errors(mixed $definition): array
    {
        $errors = $this->schema->errors($definition);

        if (! $this->isObject($definition)) {
            return $errors;
        }

        /** @var array<string, mixed> $definition */
        $origin = $this->object($definition['origin'] ?? null);
        $lon = $origin['lon'] ?? null;
        $lat = $origin['lat'] ?? null;

        if (! $this->isNumber($lon) || $lon < -180 || $lon > 180) {
            $errors[] = 'origin.lon must be -180..180';
        }

        if (! $this->isNumber($lat) || $lat < -90 || $lat > 90) {
            $errors[] = 'origin.lat must be -90..90';
        }

        if (! $this->isList($definition['assets'] ?? null)) {
            return $this->unique($errors);
        }

        /** @var list<array{string, string}> $refs */
        $refs = [];
        $types = $this->checkTypes($errors, $definition, $refs);

        /** @var array<string, array<string, mixed>> $byId */
        $byId = [];

        foreach ($this->objects($definition['assets']) as $asset) {
            $id = $asset['id'] ?? null;

            if (! $this->isText($id)) {
                continue;
            }

            /** @var string $id */
            if (array_key_exists($id, $byId)) {
                $errors[] = 'Duplicate asset id '.$id;
            }

            $byId[$id] = $asset;
        }

        $zones = [];

        foreach ($this->objects($definition['zones'] ?? null) as $zone) {
            $id = $zone['id'] ?? null;

            if (! $this->isText($id)) {
                continue;
            }

            /** @var string $id */
            if (in_array($id, $zones, true)) {
                $errors[] = 'Duplicate zone id '.$id;
            }

            $zones[] = $id;
        }

        $routes = array_map(fn (array $route): mixed => $route['id'] ?? null, $this->objects($definition['routes'] ?? null));

        foreach ($byId as $id => $asset) {
            $id = (string) $id;
            $kind = $asset['kind'] ?? null;

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

            // A cycle would otherwise hang the feed-tree walk.
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
            $length = count($this->items($route['path'] ?? null));

            foreach ($this->objects($route['stops'] ?? null) as $stop) {
                $at = $stop['at'] ?? null;

                if (is_int($at) && $at >= $length) {
                    $errors[] = sprintf('Route %s: stop at %s is off the path', $this->label($route['id'] ?? null), $this->describe($stop, 'at'));
                }
            }
        }

        foreach ($this->objects($definition['rules'] ?? null) as $rule) {
            $name = $this->label($rule['id'] ?? null);

            if (array_key_exists('asset', $rule) && ! array_key_exists($this->describe($rule, 'asset'), $byId)) {
                $errors[] = sprintf('Rule %s: unknown asset %s', $name, $this->describe($rule, 'asset'));
            }

            if (! array_key_exists('when', $rule)) {
                continue;
            }

            // Which names an asset has depends on its kind, so only the
            // gateway knows; here the expressions must parse.
            $this->checkExpression($errors, sprintf('Rule %s: when', $name), $rule['when'], null, $refs);

            if (array_key_exists('clearWhen', $rule)) {
                $this->checkExpression($errors, sprintf('Rule %s: clearWhen', $name), $rule['clearWhen'], null, $refs);
            }
        }

        // Figures for the top bar and site summary: site-wide, so they read
        // other assets by reference and have no bare names of their own.
        foreach ($this->items($definition['kpis'] ?? null) as $kpi) {
            $this->checkReadout($errors, 'KPI', $kpi, $refs);
        }

        foreach ($this->objects($definition['panels'] ?? null) as $panel) {
            $title = $panel['title'] ?? null;

            if (! $this->isText($title)) {
                continue;
            }

            /** @var string $title */
            $owner = 'Panel '.$title;

            foreach ($this->items($panel['items'] ?? null) as $item) {
                $this->checkReadout($errors, $owner, $item, $refs);
            }

            if (array_key_exists('chart', $panel)) {
                $chart = $panel['chart'];
                $this->checkReadout($errors, $owner, $this->isObject($chart) ? ['label' => 'chart', ...$chart] : $chart, $refs);
            }
        }

        foreach ($refs as [$owner, $asset]) {
            if (! array_key_exists($asset, $byId)) {
                $errors[] = sprintf('%s refers to unknown asset %s', $owner, $asset);
            }
        }

        foreach ($this->objects($definition['scenarios'] ?? null) as $scenario) {
            foreach ($this->objects($scenario['faults'] ?? null) as $fault) {
                if (array_key_exists('target', $fault) && ! array_key_exists($this->describe($fault, 'target'), $byId)) {
                    $errors[] = sprintf('Scenario %s: unknown target %s', $this->label($scenario['id'] ?? null), $this->describe($fault, 'target'));
                }
            }
        }

        // Context layers share one set of ids, across tilesets and models.
        $layers = [];

        foreach (['tilesets', 'models'] as $field) {
            foreach ($this->objects($definition[$field] ?? null) as $layer) {
                $id = $layer['id'] ?? null;

                if (! $this->isText($id)) {
                    continue;
                }

                /** @var string $id */
                if (in_array($id, $layers, true)) {
                    $errors[] = 'Duplicate layer id '.$id;
                }

                $layers[] = $id;
            }
        }

        foreach ($this->objects($definition['assets']) as $asset) {
            $feature = is_array($asset['feature'] ?? null) ? $asset['feature'] : [];
            $layer = $feature['layer'] ?? null;

            if ($this->isText($layer) && ! in_array($layer, $layers, true)) {
                /** @var string $layer */
                $errors[] = sprintf('%s: unknown layer %s', $this->label($asset['id'] ?? null), $layer);
            }
        }

        // Telemetry: which MQTT topics feed which metrics. Only the gateway knows
        // every kind's metrics, so it checks the metric when it applies one.
        foreach ($this->objects($definition['telemetry'] ?? null) as $mapping) {
            $topic = $mapping['topic'] ?? null;

            if (! $this->isText($topic)) {
                continue;
            }

            /** @var string $topic */
            $owner = 'Telemetry '.$topic;

            if (! $this->isTopicFilter($topic)) {
                $errors[] = $owner.': bad topic filter';
            }

            if (! array_key_exists($this->describe($mapping, 'asset'), $byId)) {
                $errors[] = sprintf('%s: unknown asset %s', $owner, $this->describe($mapping, 'asset'));
            }
        }

        // OPC UA: which servers' nodes feed which metrics. The servers belong to
        // the project, so only the gateway knows whether one exists.
        foreach ($this->objects($definition['opcua'] ?? null) as $mapping) {
            $node = $mapping['node'] ?? null;

            if (! $this->isText($node)) {
                continue;
            }

            /** @var string $node */
            if (! array_key_exists($this->describe($mapping, 'asset'), $byId)) {
                $errors[] = sprintf('OPC UA %s: unknown asset %s', $node, $this->describe($mapping, 'asset'));
            }
        }

        return $this->unique($errors);
    }

    /**
     * Checks one type on its own, as an organisation's library holds it:
     * everything a twin checks except that the assets it reads exist, which
     * only a twin can say. Those assets are returned as reads.
     *
     * @return array{errors: list<string>, reads: list<string>}
     */
    public function type(mixed $type): array
    {
        if (! $this->isObject($type)) {
            return ['errors' => ['A type must be a JSON object'], 'reads' => []];
        }

        $wrapped = ['types' => [$type]];
        $errors = $this->schema->errors($wrapped, 'types');
        /** @var list<array{string, string}> $refs */
        $refs = [];
        $this->checkTypes($errors, $wrapped, $refs);

        return ['errors' => $this->unique($errors), 'reads' => array_values(array_unique(array_column($refs, 1)))];
    }

    /**
     * MQTT filter rules: `+` and `#` fill a whole level, and `#` comes last.
     */
    public function isTopicFilter(string $topic): bool
    {
        $levels = explode('/', $topic);
        $last = count($levels) - 1;

        foreach ($levels as $i => $level) {
            $wild = str_contains($level, '+') || str_contains($level, '#');

            if (str_contains($level, "\0") || ($wild && $level !== '+' && ($level !== '#' || $i !== $last))) {
                return false;
            }
        }

        return true;
    }

    /**
     * What a type needs beyond its shape: unique metrics and states, a
     * primary among its metrics, and expressions that read only what the
     * type has.
     *
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $definition
     * @param  list<array{string, string}>  $refs
     * @return array<string, true>
     */
    private function checkTypes(array &$errors, array $definition, array &$refs): array
    {
        $types = [];

        foreach ($this->objects($definition['types'] ?? null) as $type) {
            $id = $type['id'] ?? null;

            if (! is_string($id) || preg_match(self::ID, $id) !== 1) {
                continue;
            }

            if (array_key_exists($id, $types)) {
                $errors[] = 'Duplicate type id '.$id;
            }

            $types[$id] = true;
            $keys = [];

            foreach ($this->objects($type['metrics'] ?? null) as $metric) {
                $key = $metric['key'] ?? null;

                if (! $this->isKey($key)) {
                    continue;
                }

                /** @var string $key */
                if (in_array($key, $keys, true)) {
                    $errors[] = sprintf('Type %s: duplicate metric %s', $id, $key);
                }

                $keys[] = $key;
            }

            if (! in_array($type['primary'] ?? null, $keys, true)) {
                $errors[] = sprintf('Type %s: primary must be one of its metrics', $id);
            }

            $states = [];

            foreach ($this->objects($type['states'] ?? null) as $state) {
                $name = $state['id'] ?? null;

                if (! $this->isKey($name)) {
                    continue;
                }

                /** @var string $name */
                if (in_array($name, $states, true)) {
                    $errors[] = sprintf('Type %s: duplicate state %s', $id, $name);
                }

                $states[] = $name;

                if (in_array($name, $keys, true) || in_array($name, self::TYPE_BUILTINS, true)) {
                    $errors[] = sprintf('Type %s: state %s clashes with a metric or built-in name', $id, $name);
                }
            }

            $allowed = [...$keys, ...$states, ...self::TYPE_BUILTINS];

            foreach ($this->objects($type['metrics'] ?? null) as $metric) {
                $key = $this->describe($metric, 'key');

                if (! in_array($key, $keys, true)) {
                    continue;
                }

                $owner = sprintf('Type %s: metric %s', $id, $key);

                if (array_key_exists('expr', $metric) && ! array_key_exists('setpoint', $metric)) {
                    $this->checkExpression($errors, $owner, $metric['expr'], $allowed, $refs);
                }

                $setpoint = $this->object($metric['setpoint'] ?? null);
                $min = $setpoint['min'] ?? null;
                $max = $setpoint['max'] ?? null;

                if ($this->isNumber($min) && $this->isNumber($max) && $min >= $max) {
                    $errors[] = $owner.' setpoint needs a min below its max';
                }
            }

            $transitions = $this->objects($type['transitions'] ?? null);

            if ($transitions !== [] && $states === []) {
                $errors[] = sprintf('Type %s: transitions need states', $id);
            } else {
                foreach ($transitions as $transition) {
                    $from = $this->describe($transition, 'from');
                    $to = $this->describe($transition, 'to');

                    if ($from !== '*' && ! in_array($from, $states, true)) {
                        $errors[] = sprintf('Type %s: transition from unknown state %s', $id, $from);
                    }

                    if (! in_array($to, $states, true)) {
                        $errors[] = sprintf('Type %s: transition to unknown state %s', $id, $to);

                        continue;
                    }

                    $this->checkExpression($errors, sprintf('Type %s: transition to %s', $id, $to), $transition['when'] ?? null, $allowed, $refs);
                }
            }

            if (! $this->isObject($type['visual'] ?? null)) {
                $errors[] = sprintf('Type %s: visual.color must be a #rrggbb colour', $id);
                $errors[] = sprintf('Type %s: visual.shape must be box or cylinder', $id);
            }
        }

        return $types;
    }

    private function isKey(mixed $value): bool
    {
        return is_string($value) && preg_match(self::METRIC_KEY, $value) === 1 && $value !== 'state';
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function unique(array $errors): array
    {
        return array_values(array_unique($errors));
    }

    /**
     * Checks one expression: that it parses, and reads only names in $allowed
     * (when given). References to other assets are collected for checking
     * once every asset is known.
     *
     * @param  list<string>  $errors
     * @param  list<string>|null  $allowed
     * @param  list<array{string, string}>  $refs
     */
    private function checkExpression(array &$errors, string $owner, mixed $source, ?array $allowed, array &$refs): void
    {
        $error = Expression::error($source);

        if ($error !== null) {
            $errors[] = sprintf('%s: %s', $owner, $error);

            return;
        }

        /** @var string $source */
        $read = Expression::references($source);

        foreach ($read['names'] as $name) {
            if ($allowed !== null && ! in_array($name, $allowed, true)) {
                $errors[] = sprintf('%s uses unknown name %s', $owner, $name);
            }
        }

        foreach ($read['refs'] as $ref) {
            $refs[] = [$owner, $ref['asset']];
        }
    }

    /**
     * A label, a value expression over references, a unit and decimals.
     * $owner names where it sits ("KPI", "Panel Fire"); the label follows.
     *
     * @param  list<string>  $errors
     * @param  list<array{string, string}>  $refs
     */
    private function checkReadout(array &$errors, string $owner, mixed $readout, array &$refs): void
    {
        $label = $this->isObject($readout) ? ($readout['label'] ?? null) : null;

        if (! $this->isText($label)) {
            $errors[] = $owner.': every figure needs a label';

            return;
        }

        /** @var array<string, mixed> $readout */
        /** @var string $label */
        $owner = $owner === 'KPI' ? 'KPI '.$label : sprintf('%s: %s', $owner, $label);
        $this->checkExpression($errors, $owner.': value', $readout['value'] ?? null, [], $refs);

        if (array_key_exists('unit', $readout) && ! is_string($readout['unit'])) {
            $errors[] = $owner.': unit must be text';
        }

        $decimals = $readout['decimals'] ?? null;

        if (array_key_exists('decimals', $readout) && ! ($this->isNumber($decimals) && floor($decimals) === (float) $decimals && $decimals >= 0 && $decimals <= 6)) {
            $errors[] = $owner.': decimals must be 0 to 6';
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
        return $this->schema->show($value);
    }
}

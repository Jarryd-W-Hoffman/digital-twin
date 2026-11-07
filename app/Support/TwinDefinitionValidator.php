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

    private const int MAX_KPIS = 8;

    private const array STATUSES = ['running', 'standby', 'off', 'fault', 'maintenance'];

    /**
     * Names every custom type's expressions can read besides its own.
     */
    private const array TYPE_BUILTINS = ['dt', 'hour', 'ambientC', 'powered'];

    private const string ID = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    private const string METRIC_KEY = '/^[a-z][A-Za-z0-9_]{0,63}$/';

    private const string PATH = '/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/';

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

        foreach (['types', 'tilesets', 'models', 'telemetry', 'kpis', 'panels'] as $field) {
            if (array_key_exists($field, $definition) && ! $this->isList($definition[$field])) {
                $errors[] = $field.' must be an array';
            }
        }

        /** @var list<array{string, string}> $refs */
        $refs = [];
        $types = $this->checkTypes($errors, $definition, $refs);

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

            if (array_key_exists('when', $rule)) {
                // Which names an asset has depends on its kind, so only the
                // gateway knows; here the expressions must parse.
                $this->checkExpression($errors, sprintf('Rule %s: when', $name), $rule['when'], null, $refs);

                if (array_key_exists('clearWhen', $rule)) {
                    $this->checkExpression($errors, sprintf('Rule %s: clearWhen', $name), $rule['clearWhen'], null, $refs);
                }

                continue;
            }

            if (! in_array($rule['op'] ?? null, ['gt', 'lt'], true)) {
                $errors[] = sprintf('Rule %s: op must be gt or lt', $name);
            }

            $overS = $this->object($rule['change'] ?? null)['overS'] ?? null;

            if (array_key_exists('change', $rule) && (! $this->isObject($rule['change']) || ! $this->isNumber($overS) || $overS <= 0)) {
                $errors[] = sprintf('Rule %s: change.overS must be positive', $name);
            }
        }

        $this->checkFigures($errors, $definition, $refs);

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

        $this->checkLayers($errors, $definition);
        $this->checkTelemetry($errors, $definition, $byId);

        return $errors;
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

        $errors = [];
        /** @var list<array{string, string}> $refs */
        $refs = [];
        $this->checkTypes($errors, ['types' => [$type]], $refs);

        return ['errors' => $errors, 'reads' => array_values(array_unique(array_column($refs, 1)))];
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
     * Which MQTT topics feed which metrics. Only the gateway knows every kind's
     * metrics, so it checks the metric when it applies a mapping.
     *
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $definition
     * @param  array<string, array<string, mixed>>  $assets
     */
    private function checkTelemetry(array &$errors, array $definition, array $assets): void
    {
        foreach ($this->items($definition['telemetry'] ?? null) as $mapping) {
            if (! $this->isObject($mapping) || ! $this->isText($mapping['topic'] ?? null)) {
                $errors[] = 'A telemetry mapping has no topic';

                continue;
            }

            /** @var array<string, mixed> $mapping */
            /** @var string $topic */
            $topic = $mapping['topic'];
            $owner = 'Telemetry '.$topic;

            if (! $this->isTopicFilter($topic)) {
                $errors[] = $owner.': bad topic filter';
            }

            if (! array_key_exists($this->describe($mapping, 'asset'), $assets)) {
                $errors[] = sprintf('%s: unknown asset %s', $owner, $this->describe($mapping, 'asset'));
            }

            $metric = $mapping['metric'] ?? null;

            if (! is_string($metric) || preg_match(self::METRIC_KEY, $metric) !== 1) {
                $errors[] = $owner.': metric must be a camelCase identifier';
            }

            if (array_key_exists('path', $mapping) && (! is_string($mapping['path']) || preg_match(self::PATH, $mapping['path']) !== 1)) {
                $errors[] = $owner.': path must be dotted keys';
            }

            foreach (['scale', 'offset'] as $field) {
                if (array_key_exists($field, $mapping) && ! $this->isNumber($mapping[$field])) {
                    $errors[] = sprintf('%s: %s must be a number', $owner, $field);
                }
            }

            if (array_key_exists('ttlS', $mapping) && (! $this->isNumber($mapping['ttlS']) || $mapping['ttlS'] <= 0)) {
                $errors[] = $owner.': ttlS must be positive';
            }
        }
    }

    /**
     * Figures for the top bar and site summary: site-wide, so they read other
     * assets by reference and have no bare names of their own.
     *
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $definition
     * @param  list<array{string, string}>  $refs
     */
    private function checkFigures(array &$errors, array $definition, array &$refs): void
    {
        $kpis = $this->items($definition['kpis'] ?? null);

        if (count($kpis) > self::MAX_KPIS) {
            $errors[] = sprintf('At most %d KPIs fit in the top bar', self::MAX_KPIS);
        }

        foreach ($kpis as $kpi) {
            $this->checkReadout($errors, 'KPI', $kpi, $refs);
        }

        foreach ($this->items($definition['panels'] ?? null) as $panel) {
            $title = $this->isObject($panel) ? ($panel['title'] ?? null) : null;

            if (! $this->isText($title)) {
                $errors[] = 'A panel needs a title';

                continue;
            }

            /** @var array<string, mixed> $panel */
            /** @var string $title */
            $owner = 'Panel '.$title;

            if (array_key_exists('items', $panel) && ! $this->isList($panel['items'])) {
                $errors[] = $owner.': items must be an array';
            }

            foreach ($this->items($panel['items'] ?? null) as $item) {
                $this->checkReadout($errors, $owner, $item, $refs);
            }

            if (array_key_exists('chart', $panel)) {
                $chart = $panel['chart'];
                $this->checkReadout($errors, $owner, $this->isObject($chart) ? ['label' => 'chart', ...$chart] : $chart, $refs);
            }
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
     * Behaviour: states, then what each expression may read.
     *
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $type
     * @param  list<string>  $keys
     * @param  list<array{string, string}>  $refs
     */
    private function checkBehaviour(array &$errors, string $id, array $type, array $keys, array &$refs): void
    {
        $states = [];

        foreach ($this->items($type['states'] ?? null) as $state) {
            $name = $this->isObject($state) ? ($state['id'] ?? null) : null;

            if (! is_string($name) || preg_match(self::METRIC_KEY, $name) !== 1 || $name === 'state') {
                $errors[] = sprintf('Type %s: state ids must be camelCase identifiers other than state', $id);

                continue;
            }

            /** @var array<string, mixed> $state */
            if (in_array($name, $states, true)) {
                $errors[] = sprintf('Type %s: duplicate state %s', $id, $name);
            }

            $states[] = $name;

            if (in_array($name, $keys, true) || in_array($name, self::TYPE_BUILTINS, true)) {
                $errors[] = sprintf('Type %s: state %s clashes with a metric or built-in name', $id, $name);
            }

            if (! in_array($state['status'] ?? null, self::STATUSES, true)) {
                $errors[] = sprintf('Type %s: state %s status must be running, standby, off, fault or maintenance', $id, $name);
            }
        }

        $allowed = [...$keys, ...$states, ...self::TYPE_BUILTINS];

        foreach ($this->objects($type['metrics'] ?? null) as $metric) {
            $key = $this->describe($metric, 'key');

            if (! in_array($key, $keys, true)) {
                continue;
            }

            $owner = sprintf('Type %s: metric %s', $id, $key);

            if (array_key_exists('expr', $metric) && array_key_exists('setpoint', $metric)) {
                $errors[] = $owner." can't have both an expr and a setpoint";
            } elseif (array_key_exists('expr', $metric)) {
                $this->checkExpression($errors, $owner, $metric['expr'], $allowed, $refs);
            } elseif (array_key_exists('setpoint', $metric)) {
                $setpoint = $this->object($metric['setpoint']);
                $min = $setpoint['min'] ?? null;
                $max = $setpoint['max'] ?? null;

                if (! $this->isNumber($min) || ! $this->isNumber($max) || $min >= $max) {
                    $errors[] = $owner.' setpoint needs a min below its max';
                }

                if (array_key_exists('step', $setpoint) && (! $this->isNumber($setpoint['step']) || $setpoint['step'] <= 0)) {
                    $errors[] = $owner.' setpoint step must be positive';
                }
            }
        }

        $transitions = $this->objects($type['transitions'] ?? null);

        if ($transitions !== [] && $states === []) {
            $errors[] = sprintf('Type %s: transitions need states', $id);

            return;
        }

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

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $definition
     * @param  list<array{string, string}>  $refs
     * @return array<string, true>
     */
    private function checkTypes(array &$errors, array $definition, array &$refs): array
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

                if (! is_string($key) || preg_match(self::METRIC_KEY, $key) !== 1 || $key === 'state') {
                    $errors[] = sprintf('Type %s: metric keys must be camelCase identifiers other than state', $id);

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

            $this->checkBehaviour($errors, $id, $type, $keys, $refs);

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

        if (array_key_exists('terrain', $definition)) {
            $terrain = $definition['terrain'];

            if (! $this->isObject($terrain) || ! $this->isText($terrain['url'] ?? null)) {
                $errors[] = 'Terrain needs a url';
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

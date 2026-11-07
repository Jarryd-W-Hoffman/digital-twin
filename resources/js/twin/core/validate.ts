import { expressionError, namesIn, parseExpression } from './expr.ts';
import type { AssetKind, TwinDefinition } from './types.ts';

/**
 * The PHP control plane runs the same checks (app/Support/TwinDefinitionValidator.php)
 * and both are held to tests/fixtures/definition-cases.json, so keep the
 * messages and their order identical when changing either.
 */
const KINDS: readonly AssetKind[] = [
    'site',
    'weather',
    'grid',
    'solar',
    'battery',
    'genset',
    'bus',
    'chiller',
    'coldstore',
    'building',
    'charger',
    'vehicle',
    'custom',
];
const SHAPES = ['box', 'cylinder'];
const STATUSES = ['running', 'standby', 'off', 'fault', 'maintenance'];
/** Names every custom type's expressions can read besides its own. */
export const TYPE_BUILTINS = ['dt', 'hour', 'ambientC', 'powered'];
const ID = /^[a-z0-9][a-z0-9_-]{0,63}$/;
/** Metric keys follow the built-in ones: camelCase like tempC or socPct. */
const METRIC_KEY = /^[a-z][A-Za-z0-9_]{0,63}$/;
const PATH = /^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*$/;

/** MQTT filter rules: `+` and `#` fill a whole level, and `#` comes last. */
export function isTopicFilter(topic: string): boolean {
    const levels = topic.split('/');
    return levels.every(
        (level, i) =>
            !level.includes('\0') &&
            (level === '+' ||
                (level === '#' && i === levels.length - 1) ||
                (!level.includes('+') && !level.includes('#'))),
    );
}

type Obj = Record<string, unknown>;

const isObj = (v: unknown): v is Obj =>
    typeof v === 'object' && v !== null && !Array.isArray(v);
const isNum = (v: unknown): v is number =>
    typeof v === 'number' && Number.isFinite(v);
const isText = (v: unknown): v is string => typeof v === 'string' && v !== '';
const list = (v: unknown): unknown[] => (Array.isArray(v) ? v : []);
const objects = (v: unknown): Obj[] => list(v).filter(isObj);
const label = (v: unknown): string => (isText(v) ? v : '?');
/** Prints a value the way String() would, so the PHP mirror can match it. */
const show = (v: unknown): string =>
    Array.isArray(v)
        ? v.map(show).join(',')
        : typeof v === 'object' && v !== null
          ? '[object Object]'
          : String(v as string | number | boolean | null | undefined);
const isPoint = (v: unknown): boolean =>
    Array.isArray(v) && v.length === 2 && isNum(v[0]) && isNum(v[1]);

/**
 * Checks one expression: that it parses, and reads only names in `allowed`
 * (when given). References to other assets are collected for checking once
 * every asset is known.
 */
function checkExpression(
    errors: string[],
    owner: string,
    src: unknown,
    allowed: Set<string> | null,
    refs: [string, string][],
): void {
    const error = expressionError(src);
    if (error !== null) {
        errors.push(`${owner}: ${error}`);
        return;
    }
    const read = namesIn(parseExpression(src as string));
    for (const name of read.names) {
        if (allowed && !allowed.has(name))
            errors.push(`${owner} uses unknown name ${name}`);
    }
    for (const ref of read.refs) refs.push([owner, ref.asset]);
}

const MAX_KPIS = 8;

/**
 * A label, a value expression over references, a unit and decimals. `owner`
 * names where it sits ("KPI", "Panel Fire"); the figure's label follows.
 */
function checkReadout(
    errors: string[],
    owner: string,
    r: unknown,
    refs: [string, string][],
): void {
    if (!isObj(r) || !isText(r.label)) {
        errors.push(`${owner}: every figure needs a label`);
        return;
    }
    owner = owner === 'KPI' ? `KPI ${r.label}` : `${owner}: ${r.label}`;
    checkExpression(errors, `${owner}: value`, r.value, new Set(), refs);
    if (r.unit !== undefined && typeof r.unit !== 'string')
        errors.push(`${owner}: unit must be text`);
    if (
        r.decimals !== undefined &&
        !(
            Number.isInteger(r.decimals) &&
            (r.decimals as number) >= 0 &&
            (r.decimals as number) <= 6
        )
    )
        errors.push(`${owner}: decimals must be 0 to 6`);
}

function checkPlacement(errors: string[], owner: string, p: unknown): void {
    if (!isObj(p)) {
        errors.push(`${owner}: placement must be an object`);
        return;
    }
    for (const f of ['east', 'north', 'up', 'headingDeg']) {
        if (!isNum(p[f]))
            errors.push(`${owner}: placement.${f} must be a number`);
    }
    if (!isNum(p.scale) || p.scale <= 0)
        errors.push(`${owner}: placement.scale must be positive`);
}

/**
 * Checks a definition before anything tries to run it. Returns every problem
 * rather than the first, so someone authoring a twin can fix a file in one pass.
 */
export function validateDefinition(input: unknown): string[] {
    if (!isObj(input)) return ['Definition must be a JSON object'];
    const def = input;
    const errors: string[] = [];

    for (const field of ['id', 'name']) {
        if (!isText(def[field])) errors.push(`Missing ${field}`);
    }
    const origin = isObj(def.origin) ? def.origin : {};
    if (!isNum(origin.lon) || origin.lon < -180 || origin.lon > 180)
        errors.push('origin.lon must be -180..180');
    if (!isNum(origin.lat) || origin.lat < -90 || origin.lat > 90)
        errors.push('origin.lat must be -90..90');
    for (const field of ['tariff', 'climate', 'camera']) {
        if (!isObj(def[field])) errors.push(`Missing ${field}`);
    }
    if (!Array.isArray(def.assets))
        return [...errors, 'assets must be an array'];
    for (const field of ['zones', 'routes', 'rules', 'scenarios']) {
        if (!Array.isArray(def[field]))
            errors.push(`${field} must be an array`);
    }
    for (const field of [
        'types',
        'tilesets',
        'models',
        'telemetry',
        'kpis',
        'panels',
    ]) {
        if (def[field] !== undefined && !Array.isArray(def[field]))
            errors.push(`${field} must be an array`);
    }

    // Custom asset types: data only, so a twin can describe equipment the
    // simulator has no physics for and feed it from real readings.
    const types = new Map<string, Obj>();
    const refs: [owner: string, asset: string][] = [];
    for (const t of list(def.types)) {
        if (!isObj(t) || !isText(t.id) || !ID.test(t.id)) {
            errors.push(
                'A type needs an id of lowercase letters, digits, - or _',
            );
            continue;
        }
        if (types.has(t.id)) errors.push(`Duplicate type id ${t.id}`);
        types.set(t.id, t);
        if (!isText(t.name)) errors.push(`Type ${t.id}: needs a name`);
        const metrics = list(t.metrics);
        if (!Array.isArray(t.metrics) || metrics.length === 0)
            errors.push(`Type ${t.id}: needs at least one metric`);
        const keys = new Set<string>();
        for (const m of metrics) {
            if (
                !isObj(m) ||
                !isText(m.key) ||
                !METRIC_KEY.test(m.key) ||
                m.key === 'state'
            ) {
                errors.push(
                    `Type ${t.id}: metric keys must be camelCase identifiers other than state`,
                );
                continue;
            }
            if (keys.has(m.key))
                errors.push(`Type ${t.id}: duplicate metric ${m.key}`);
            keys.add(m.key);
            if (typeof m.unit !== 'string')
                errors.push(`Type ${t.id}: metric ${m.key} needs a unit`);
            if (m.default !== undefined && !isNum(m.default))
                errors.push(
                    `Type ${t.id}: metric ${m.key} default must be a number`,
                );
        }
        if (typeof t.primary !== 'string' || !keys.has(t.primary))
            errors.push(`Type ${t.id}: primary must be one of its metrics`);

        // Behaviour: states, then what each expression may read.
        const states = new Set<string>();
        for (const st of list(t.states)) {
            if (
                !isObj(st) ||
                !isText(st.id) ||
                !METRIC_KEY.test(st.id) ||
                st.id === 'state'
            ) {
                errors.push(
                    `Type ${t.id}: state ids must be camelCase identifiers other than state`,
                );
                continue;
            }
            if (states.has(st.id))
                errors.push(`Type ${t.id}: duplicate state ${st.id}`);
            states.add(st.id);
            if (keys.has(st.id) || TYPE_BUILTINS.includes(st.id))
                errors.push(
                    `Type ${t.id}: state ${st.id} clashes with a metric or built-in name`,
                );
            if (!STATUSES.includes(show(st.status)))
                errors.push(
                    `Type ${t.id}: state ${st.id} status must be running, standby, off, fault or maintenance`,
                );
        }
        const allowed = new Set([...keys, ...states, ...TYPE_BUILTINS]);
        for (const m of objects(t.metrics)) {
            if (!keys.has(show(m.key))) continue;
            const owner = `Type ${t.id}: metric ${show(m.key)}`;
            if (m.expr !== undefined && m.setpoint !== undefined)
                errors.push(`${owner} can't have both an expr and a setpoint`);
            else if (m.expr !== undefined)
                checkExpression(errors, owner, m.expr, allowed, refs);
            else if (m.setpoint !== undefined) {
                const sp = isObj(m.setpoint) ? m.setpoint : {};
                if (!isNum(sp.min) || !isNum(sp.max) || sp.min >= sp.max)
                    errors.push(`${owner} setpoint needs a min below its max`);
                if (sp.step !== undefined && !(isNum(sp.step) && sp.step > 0))
                    errors.push(`${owner} setpoint step must be positive`);
            }
        }
        const transitions = objects(t.transitions);
        if (transitions.length > 0 && states.size === 0)
            errors.push(`Type ${t.id}: transitions need states`);
        else {
            for (const tr of transitions) {
                if (tr.from !== '*' && !states.has(show(tr.from)))
                    errors.push(
                        `Type ${t.id}: transition from unknown state ${show(tr.from)}`,
                    );
                if (!states.has(show(tr.to))) {
                    errors.push(
                        `Type ${t.id}: transition to unknown state ${show(tr.to)}`,
                    );
                    continue;
                }
                checkExpression(
                    errors,
                    `Type ${t.id}: transition to ${show(tr.to)}`,
                    tr.when,
                    allowed,
                    refs,
                );
            }
        }
        const visual = isObj(t.visual) ? t.visual : {};
        if (
            typeof visual.color !== 'string' ||
            !/^#[0-9a-f]{6}$/i.test(visual.color)
        ) {
            errors.push(`Type ${t.id}: visual.color must be a #rrggbb colour`);
        }
        if (!SHAPES.includes(show(visual.shape)))
            errors.push(`Type ${t.id}: visual.shape must be box or cylinder`);
    }

    const assets = def.assets;
    const ids = new Set<string>();
    const byId = new Map<string, Obj>();
    for (const a of assets) {
        if (!isObj(a) || !isText(a.id)) {
            errors.push('An asset has no id');
            continue;
        }
        if (ids.has(a.id)) errors.push(`Duplicate asset id ${a.id}`);
        ids.add(a.id);
        byId.set(a.id, a);
        if (!KINDS.includes(a.kind as AssetKind))
            errors.push(`${a.id}: unknown kind ${show(a.kind)}`);
    }
    const zones = new Set(objects(def.zones).map((z) => z.id));
    const routes = new Set(objects(def.routes).map((r) => r.id));
    for (const a of byId.values()) {
        const id = show(a.id);
        if (
            a.footprint === undefined &&
            a.position === undefined &&
            a.kind !== 'vehicle' &&
            a.kind !== 'site'
        ) {
            errors.push(`${id}: needs a footprint or position`);
        }
        if (
            a.footprint !== undefined &&
            (!Array.isArray(a.footprint) ||
                a.footprint.length < 3 ||
                !a.footprint.every(isPoint))
        ) {
            errors.push(`${id}: footprint needs 3+ points`);
        }
        if (a.position !== undefined && !isPoint(a.position))
            errors.push(`${id}: position must be [east, north]`);
        if (a.zone !== undefined && a.zone !== '' && !zones.has(a.zone))
            errors.push(`${id}: unknown zone ${show(a.zone)}`);
        if (a.fedBy !== undefined && !ids.has(show(a.fedBy)))
            errors.push(`${id}: fedBy unknown asset ${show(a.fedBy)}`);
        for (const s of list(a.cools)) {
            if (byId.get(show(s))?.kind !== 'coldstore')
                errors.push(
                    `${id}: cools ${show(s)}, which is not a cold store`,
                );
        }
        if (a.kind === 'vehicle' && !routes.has(a.route))
            errors.push(`${id}: unknown route ${show(a.route)}`);
        if (a.kind === 'custom' && !types.has(show(a.type)))
            errors.push(`${id}: unknown type ${show(a.type)}`);
        // A cycle would otherwise hang the feed-tree walk.
        const seen = new Set([id]);
        for (
            let cursor = a.fedBy;
            typeof cursor === 'string';
            cursor = byId.get(cursor)?.fedBy
        ) {
            if (seen.has(cursor)) {
                errors.push(`${id}: fedBy forms a cycle`);
                break;
            }
            seen.add(cursor);
        }
    }
    const mains = [...byId.values()].filter(
        (a) => a.kind === 'bus' && a.fedBy === undefined,
    );
    if (mains.length !== 1)
        errors.push('Twin needs exactly one main bus (a bus with no fedBy)');

    for (const r of objects(def.routes)) {
        const path = list(r.path);
        if (!Array.isArray(r.path) || path.length < 2)
            errors.push(`Route ${label(r.id)}: path needs 2+ points`);
        for (const s of objects(r.stops)) {
            if (
                !Number.isInteger(s.at) ||
                (s.at as number) < 0 ||
                (s.at as number) >= path.length
            ) {
                errors.push(
                    `Route ${label(r.id)}: stop at ${show(s.at)} is off the path`,
                );
            }
            if (!isNum(s.dwellS) || s.dwellS <= 0)
                errors.push(
                    `Route ${label(r.id)}: stop dwell must be positive`,
                );
        }
    }
    for (const r of objects(def.rules)) {
        if (r.kind === undefined && r.asset === undefined)
            errors.push(`Rule ${label(r.id)}: needs a kind or an asset`);
        if (r.asset !== undefined && !ids.has(show(r.asset)))
            errors.push(`Rule ${label(r.id)}: unknown asset ${show(r.asset)}`);
        if (r.when !== undefined) {
            // Which names an asset has depends on its kind, so only the
            // gateway knows; here the expressions must parse.
            checkExpression(
                errors,
                `Rule ${label(r.id)}: when`,
                r.when,
                null,
                refs,
            );
            if (r.clearWhen !== undefined)
                checkExpression(
                    errors,
                    `Rule ${label(r.id)}: clearWhen`,
                    r.clearWhen,
                    null,
                    refs,
                );
            continue;
        }
        if (r.op !== 'gt' && r.op !== 'lt')
            errors.push(`Rule ${label(r.id)}: op must be gt or lt`);
        if (
            r.change !== undefined &&
            !(isObj(r.change) && isNum(r.change.overS) && r.change.overS > 0)
        )
            errors.push(`Rule ${label(r.id)}: change.overS must be positive`);
    }
    // Figures for the top bar and site summary: site-wide, so they read
    // other assets by reference and have no bare names of their own.
    const kpis = list(def.kpis);
    if (kpis.length > MAX_KPIS)
        errors.push(`At most ${MAX_KPIS} KPIs fit in the top bar`);
    for (const k of kpis) checkReadout(errors, 'KPI', k, refs);
    for (const panel of list(def.panels)) {
        if (!isObj(panel) || !isText(panel.title)) {
            errors.push('A panel needs a title');
            continue;
        }
        const owner = `Panel ${panel.title}`;
        if (panel.items !== undefined && !Array.isArray(panel.items))
            errors.push(`${owner}: items must be an array`);
        for (const item of list(panel.items))
            checkReadout(errors, owner, item, refs);
        if (panel.chart !== undefined)
            checkReadout(
                errors,
                owner,
                isObj(panel.chart)
                    ? { label: 'chart', ...panel.chart }
                    : panel.chart,
                refs,
            );
    }

    for (const [owner, asset] of refs) {
        if (!ids.has(asset))
            errors.push(`${owner} refers to unknown asset ${asset}`);
    }
    for (const s of objects(def.scenarios)) {
        for (const f of objects(s.faults)) {
            if (f.target !== undefined && !ids.has(show(f.target)))
                errors.push(
                    `Scenario ${label(s.id)}: unknown target ${show(f.target)}`,
                );
        }
    }

    // Context layers: uploaded tilesets keep their own georeference unless
    // placed; uploaded models are always placed relative to the origin.
    const layers = new Set<string>();
    for (const [field, noun] of [
        ['tilesets', 'Tileset'],
        ['models', 'Model'],
    ] as const) {
        for (const l of list(def[field])) {
            if (!isObj(l) || !isText(l.id)) {
                errors.push(`A ${noun.toLowerCase()} has no id`);
                continue;
            }
            if (layers.has(l.id)) errors.push(`Duplicate layer id ${l.id}`);
            layers.add(l.id);
            if (!isText(l.url)) errors.push(`${noun} ${l.id}: needs a url`);
            if (field === 'models' || l.placement !== undefined)
                checkPlacement(errors, `${noun} ${l.id}`, l.placement);
        }
    }

    // Telemetry: which MQTT topics feed which metrics. Only the gateway knows
    // every kind's metrics, so it checks the metric when it applies one.
    for (const t of list(def.telemetry)) {
        if (!isObj(t) || !isText(t.topic)) {
            errors.push('A telemetry mapping has no topic');
            continue;
        }
        const owner = `Telemetry ${t.topic}`;
        if (!isTopicFilter(t.topic)) errors.push(`${owner}: bad topic filter`);
        if (!ids.has(show(t.asset)))
            errors.push(`${owner}: unknown asset ${show(t.asset)}`);
        if (typeof t.metric !== 'string' || !METRIC_KEY.test(t.metric))
            errors.push(`${owner}: metric must be a camelCase identifier`);
        if (
            t.path !== undefined &&
            (typeof t.path !== 'string' || !PATH.test(t.path))
        )
            errors.push(`${owner}: path must be dotted keys`);
        for (const f of ['scale', 'offset']) {
            if (t[f] !== undefined && !isNum(t[f]))
                errors.push(`${owner}: ${f} must be a number`);
        }
        if (t.ttlS !== undefined && !(isNum(t.ttlS) && t.ttlS > 0))
            errors.push(`${owner}: ttlS must be positive`);
    }
    return errors;
}

export function parseDefinition(input: unknown): TwinDefinition {
    const errors = validateDefinition(input);
    if (errors.length)
        throw new Error(`Invalid twin definition:\n- ${errors.join('\n- ')}`);
    return input as TwinDefinition;
}

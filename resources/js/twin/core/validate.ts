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
const ID = /^[a-z0-9][a-z0-9_-]{0,63}$/;

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
    for (const field of ['types', 'tilesets', 'models']) {
        if (def[field] !== undefined && !Array.isArray(def[field]))
            errors.push(`${field} must be an array`);
    }

    // Custom asset types: data only, so a twin can describe equipment the
    // simulator has no physics for and feed it from real readings.
    const types = new Map<string, Obj>();
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
                !ID.test(m.key) ||
                m.key === 'state'
            ) {
                errors.push(
                    `Type ${t.id}: metric keys must be lowercase identifiers other than state`,
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
            errors.push(
                `Rule ${label(r.id)}: unknown asset ${show(r.asset)}`,
            );
        if (r.op !== 'gt' && r.op !== 'lt')
            errors.push(`Rule ${label(r.id)}: op must be gt or lt`);
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
    return errors;
}

export function parseDefinition(input: unknown): TwinDefinition {
    const errors = validateDefinition(input);
    if (errors.length)
        throw new Error(`Invalid twin definition:\n- ${errors.join('\n- ')}`);
    return input as TwinDefinition;
}

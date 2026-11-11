import { expressionError, namesIn, parseExpression } from './expr.ts';
import { schemaErrors, show } from './schema.ts';
import type { TwinDefinition } from './types.ts';

/**
 * The PHP control plane runs the same checks (app/Support/TwinDefinitionValidator.php)
 * and both are held to tests/fixtures/definition-cases.json, so keep the
 * messages and their order identical when changing either. Checks of shape
 * belong in definition.schema.json, which both read.
 */
/** Names every custom type's expressions can read besides its own. */
export const TYPE_BUILTINS = ['dt', 'hour', 'ambientC', 'powered'];
const ID = /^[a-z0-9][a-z0-9_-]{0,63}$/;
/** Metric keys follow the built-in ones: camelCase like tempC or socPct. */
const METRIC_KEY = /^[a-z][A-Za-z0-9_]{0,63}$/;

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
const unique = (errors: string[]): string[] => [...new Set(errors)];

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

/**
 * Checks a definition before anything tries to run it. Returns every problem
 * rather than the first, so someone authoring a twin can fix a file in one pass:
 * first what definition.schema.json finds about its shape, then what needs the
 * whole definition (references, duplicates, cycles, expressions).
 */
export function validateDefinition(input: unknown): string[] {
    const errors = schemaErrors(input);
    if (!isObj(input)) return errors;
    const def = input;

    const origin = isObj(def.origin) ? def.origin : {};
    if (!isNum(origin.lon) || origin.lon < -180 || origin.lon > 180)
        errors.push('origin.lon must be -180..180');
    if (!isNum(origin.lat) || origin.lat < -90 || origin.lat > 90)
        errors.push('origin.lat must be -90..90');
    if (!Array.isArray(def.assets)) return unique(errors);

    // Custom asset types: data only, so a twin can describe equipment the
    // simulator has no physics for and feed it from real readings.
    const types = new Map<string, Obj>();
    const refs: [owner: string, asset: string][] = [];
    for (const t of list(def.types)) {
        if (!isObj(t) || !isText(t.id) || !ID.test(t.id)) continue;
        if (types.has(t.id)) errors.push(`Duplicate type id ${t.id}`);
        types.set(t.id, t);
        const keys = new Set<string>();
        for (const m of objects(t.metrics)) {
            if (!isText(m.key) || !METRIC_KEY.test(m.key) || m.key === 'state')
                continue;
            if (keys.has(m.key))
                errors.push(`Type ${t.id}: duplicate metric ${m.key}`);
            keys.add(m.key);
        }
        if (typeof t.primary !== 'string' || !keys.has(t.primary))
            errors.push(`Type ${t.id}: primary must be one of its metrics`);

        // Behaviour: states, then what each expression may read.
        const states = new Set<string>();
        for (const st of objects(t.states)) {
            if (!isText(st.id) || !METRIC_KEY.test(st.id) || st.id === 'state')
                continue;
            if (states.has(st.id))
                errors.push(`Type ${t.id}: duplicate state ${st.id}`);
            states.add(st.id);
            if (keys.has(st.id) || TYPE_BUILTINS.includes(st.id))
                errors.push(
                    `Type ${t.id}: state ${st.id} clashes with a metric or built-in name`,
                );
        }
        const allowed = new Set([...keys, ...states, ...TYPE_BUILTINS]);
        for (const m of objects(t.metrics)) {
            if (!keys.has(show(m.key))) continue;
            const owner = `Type ${t.id}: metric ${show(m.key)}`;
            if (m.expr !== undefined && m.setpoint === undefined)
                checkExpression(errors, owner, m.expr, allowed, refs);
            const sp = isObj(m.setpoint) ? m.setpoint : {};
            if (isNum(sp.min) && isNum(sp.max) && sp.min >= sp.max)
                errors.push(`${owner} setpoint needs a min below its max`);
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
        if (!isObj(t.visual))
            errors.push(
                `Type ${t.id}: visual.color must be a #rrggbb colour`,
                `Type ${t.id}: visual.shape must be box or cylinder`,
            );
    }

    const ids = new Set<string>();
    const byId = new Map<string, Obj>();
    for (const a of def.assets) {
        if (!isObj(a) || !isText(a.id)) continue;
        if (ids.has(a.id)) errors.push(`Duplicate asset id ${a.id}`);
        ids.add(a.id);
        byId.set(a.id, a);
    }
    const zones = new Set<unknown>();
    for (const z of objects(def.zones)) {
        if (!isText(z.id)) continue;
        if (zones.has(z.id)) errors.push(`Duplicate zone id ${z.id}`);
        zones.add(z.id);
    }
    const routes = new Set(objects(def.routes).map((r) => r.id));
    for (const a of byId.values()) {
        const id = show(a.id);
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
        const length = list(r.path).length;
        for (const s of objects(r.stops)) {
            if (Number.isInteger(s.at) && (s.at as number) >= length)
                errors.push(
                    `Route ${label(r.id)}: stop at ${show(s.at)} is off the path`,
                );
        }
    }
    for (const r of objects(def.rules)) {
        if (r.asset !== undefined && !ids.has(show(r.asset)))
            errors.push(`Rule ${label(r.id)}: unknown asset ${show(r.asset)}`);
        if (r.when === undefined) continue;
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
    }
    // Figures for the top bar and site summary: site-wide, so they read
    // other assets by reference and have no bare names of their own.
    for (const k of list(def.kpis)) checkReadout(errors, 'KPI', k, refs);
    for (const panel of objects(def.panels)) {
        if (!isText(panel.title)) continue;
        const owner = `Panel ${panel.title}`;
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
    // Shapes: where they stand, and expressions that read other assets by
    // reference, as figures do.
    const shapes = new Set<string>();
    for (const shape of objects(def.shapes)) {
        if (!isText(shape.id)) continue;
        const owner = `Shape ${shape.id}`;
        if (shapes.has(shape.id)) errors.push(`Duplicate shape id ${shape.id}`);
        shapes.add(shape.id);
        if (shape.asset !== undefined && !ids.has(show(shape.asset)))
            errors.push(`${owner}: unknown asset ${show(shape.asset)}`);
        for (const key of ['heading', 'tilt', 'length'] as const) {
            if (shape[key] !== undefined)
                checkExpression(
                    errors,
                    `${owner}: ${key}`,
                    shape[key],
                    new Set(),
                    refs,
                );
        }
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

    // Context layers share one set of ids, across tilesets and models.
    const layers = new Set<string>();
    for (const field of ['tilesets', 'models']) {
        for (const l of objects(def[field])) {
            if (!isText(l.id)) continue;
            if (layers.has(l.id)) errors.push(`Duplicate layer id ${l.id}`);
            layers.add(l.id);
        }
    }
    for (const a of objects(def.assets)) {
        const feature = a.feature as Record<string, unknown> | undefined;
        if (isText(feature?.layer) && !layers.has(feature.layer))
            errors.push(`${label(a.id)}: unknown layer ${feature.layer}`);
    }

    // Telemetry: which MQTT topics feed which metrics. Only the gateway knows
    // every kind's metrics, so it checks the metric when it applies one.
    for (const t of objects(def.telemetry)) {
        if (!isText(t.topic)) continue;
        const owner = `Telemetry ${t.topic}`;
        if (!isTopicFilter(t.topic)) errors.push(`${owner}: bad topic filter`);
        if (!ids.has(show(t.asset)))
            errors.push(`${owner}: unknown asset ${show(t.asset)}`);
    }
    // OPC UA: which servers' nodes feed which metrics. The servers belong to
    // the project, so only the gateway knows whether one exists.
    for (const m of objects(def.opcua)) {
        if (!isText(m.node)) continue;
        if (!ids.has(show(m.asset)))
            errors.push(`OPC UA ${m.node}: unknown asset ${show(m.asset)}`);
        else if (m.write === true) {
            // Only what an operator may set goes back to a server.
            const asset = byId.get(show(m.asset))!;
            const type = types.get(show(asset.type));
            const metric = objects(type?.metrics).find(
                (x) => x.key === m.metric,
            );
            if (asset.kind !== 'custom' || !isObj(metric?.setpoint))
                errors.push(
                    `OPC UA ${m.node}: ${show(m.asset)} ${show(m.metric)} is not a setpoint, so it can't be written`,
                );
            if (m.scale === 0)
                errors.push(
                    `OPC UA ${m.node}: a written mapping can't have a scale of 0`,
                );
        }
    }
    return unique(errors);
}

export function parseDefinition(input: unknown): TwinDefinition {
    const errors = validateDefinition(input);
    if (errors.length)
        throw new Error(`Invalid twin definition:\n- ${errors.join('\n- ')}`);
    return input as TwinDefinition;
}

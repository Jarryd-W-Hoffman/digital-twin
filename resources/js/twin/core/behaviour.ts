import { evaluate, parseExpression, type Node, type Scope } from './expr.ts';
import type {
    AssetDef,
    AssetTypeDef,
    StateCode,
    StatusName,
    TwinDefinition,
    TypeMetricDef,
    TypeStateDef,
    Values,
} from './types.ts';
import { State } from './types.ts';

/**
 * Custom asset types with behaviour written as data: metrics worked out from
 * expressions, states moved between by transitions, and setpoints operators
 * may change. Compiled once per definition, then stepped with the model.
 */

export const STATUS_CODE: Record<StatusName, StateCode> = {
    running: State.Running,
    standby: State.Standby,
    off: State.Off,
    fault: State.Fault,
    maintenance: State.Maintenance,
};

export interface CompiledType {
    type: AssetTypeDef;
    derived: { key: string; node: Node }[];
    states: TypeStateDef[];
    /** `from` is a state index, or -1 for any state. */
    transitions: { from: number; to: number; node: Node }[];
    setpoints: Map<string, NonNullable<TypeMetricDef['setpoint']>>;
    /** Draws power from its `fedBy` bus, as its powerKw metric says. */
    drawsPower: boolean;
}

/**
 * Where a custom asset keeps its current state's index and whether its bus
 * is energised. `$` can't start a metric key, so these never collide.
 */
export const MODE = '$mode';
export const POWERED = '$powered';

/** Built-in names a type's expressions can read; see TYPE_BUILTINS. */
export interface StepContext {
    dt: number;
    hour: number;
    ambientC: number;
}

export function compileTypes(def: TwinDefinition): Map<string, CompiledType> {
    const compiled = new Map<string, CompiledType>();
    for (const type of def.types ?? []) {
        const states = type.states ?? [];
        const index = (id: string) => states.findIndex((s) => s.id === id);
        compiled.set(type.id, {
            type,
            derived: type.metrics.flatMap((m) =>
                m.expr === undefined
                    ? []
                    : [{ key: m.key, node: parseExpression(m.expr) }],
            ),
            states,
            transitions: (type.transitions ?? []).map((t) => ({
                from: t.from === '*' ? -1 : index(t.from),
                to: index(t.to),
                node: parseExpression(t.when),
            })),
            setpoints: new Map(
                type.metrics.flatMap((m) =>
                    m.setpoint ? [[m.key, m.setpoint] as const] : [],
                ),
            ),
            drawsPower: type.metrics.some((m) => m.key === 'powerKw'),
        });
    }
    return compiled;
}

/**
 * What an asset's expressions can read: its own metrics (and, for a custom
 * type, its states as 1 or 0), other assets as `{asset.metric}`, and, while
 * the model steps, the built-in names.
 */
export function assetScope(
    asset: AssetDef,
    compiled: CompiledType | undefined,
    values: Values,
    context?: StepContext,
): Scope {
    const mode = values[`${asset.id}.${MODE}`] ?? 0;
    return {
        name(name) {
            const own = values[`${asset.id}.${name}`];
            if (own !== undefined) return own;
            const state = compiled?.states.findIndex((s) => s.id === name);
            if (state !== undefined && state >= 0)
                return state === mode ? 1 : 0;
            if (name === 'powered') return values[`${asset.id}.${POWERED}`];
            if (context && name in context)
                return context[name as keyof StepContext];
            return undefined;
        },
        ref: (other, metric) => values[`${other}.${metric}`],
    };
}

export function initialCustom(
    asset: AssetDef,
    compiled: CompiledType | undefined,
    values: Values,
): void {
    for (const metric of compiled?.type.metrics ?? []) {
        values[`${asset.id}.${metric.key}`] =
            metric.default ?? metric.setpoint?.min ?? 0;
    }
    values[`${asset.id}.${MODE}`] = 0;
    values[`${asset.id}.${POWERED}`] = 1;
    values[`${asset.id}.state`] = statusOf(compiled, 0);
}

/**
 * One step of a custom asset: take the first transition that holds, then
 * work out each derived metric in order. A metric whose expression has no
 * value (it reads something missing, or divides by zero) keeps its last one.
 */
export function stepCustom(
    asset: AssetDef,
    compiled: CompiledType,
    values: Values,
    context: StepContext,
): void {
    const modeKey = `${asset.id}.${MODE}`;
    const mode = values[modeKey] ?? 0;
    for (const t of compiled.transitions) {
        if (t.to === mode || (t.from !== -1 && t.from !== mode)) continue;
        const holds = evaluate(
            t.node,
            assetScope(asset, compiled, values, context),
        );
        if (Number.isNaN(holds) || holds === 0) continue;
        values[modeKey] = t.to;
        break;
    }
    for (const d of compiled.derived) {
        const value = evaluate(
            d.node,
            assetScope(asset, compiled, values, context),
        );
        if (!Number.isNaN(value)) values[`${asset.id}.${d.key}`] = value;
    }
}

/** The status code a custom asset shows in its current state. */
export function statusOf(
    compiled: CompiledType | undefined,
    mode: number,
): StateCode {
    const state = compiled?.states[mode];
    return state ? STATUS_CODE[state.status] : State.Running;
}

/** The label of a custom asset's current state, if its type has states. */
export function stateLabelOf(
    def: TwinDefinition | undefined,
    asset: AssetDef,
    values: Values,
): string | undefined {
    if (asset.kind !== 'custom') return undefined;
    const type = def?.types?.find((t) => t.id === asset.type);
    const state = type?.states?.[values[`${asset.id}.${MODE}`] ?? 0];
    return state ? (state.label ?? state.id) : undefined;
}

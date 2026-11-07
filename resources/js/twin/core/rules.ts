import { assetScope, compileTypes, type CompiledType } from './behaviour.ts';
import { evaluate, parseExpression, type Node } from './expr.ts';
import type {
    Alert,
    AssetDef,
    Frame,
    RuleDef,
    TwinDefinition,
} from './types.ts';

/**
 * Evaluates rules against any frame, whichever source produced it, so a live
 * feed gets the same alerting as the simulator. A rule must stay breached for
 * `forS` before it raises, and clears only past `clearAt` (or once
 * `clearWhen` holds), which stops noise from chattering an alert open and
 * closed.
 *
 * Three shapes of rule: a metric against a threshold; how much a metric
 * changed over a window against a threshold; and an expression (`when`).
 */

interface Binding {
    rule: RuleDef;
    asset: AssetDef;
    type: CompiledType | undefined;
    key: string;
    when?: Node;
    clearWhen?: Node;
    /** Change rules: recent readings, oldest first. */
    samples: { t: number; v: number }[];
    breachedSince?: number;
    alert?: Alert;
}

export interface RuleEngine {
    evaluate(frame: Frame): { raised: Alert[]; cleared: Alert[] };
    /** Every alert ever raised, oldest first. */
    alerts(): Alert[];
    acknowledge(alertId: string, at: number): void;
}

const holds = (v: number) => !Number.isNaN(v) && v !== 0;

export function createRuleEngine(def: TwinDefinition): RuleEngine {
    const types = compileTypes(def);
    const bindings: Binding[] = [];
    for (const rule of def.rules) {
        for (const asset of def.assets) {
            if (rule.asset === asset.id || rule.kind === asset.kind) {
                bindings.push({
                    rule,
                    asset,
                    type: types.get(asset.type ?? ''),
                    key: `${asset.id}.${rule.metric ?? ''}`,
                    when:
                        rule.when === undefined
                            ? undefined
                            : parseExpression(rule.when),
                    clearWhen:
                        rule.clearWhen === undefined
                            ? undefined
                            : parseExpression(rule.clearWhen),
                    samples: [],
                });
            }
        }
    }
    const all: Alert[] = [];

    /** Whether the binding is breached now, and the value to show for it. */
    const breach = (
        b: Binding,
        frame: Frame,
    ): { breached: boolean; cleared: boolean; value: number } | null => {
        const { rule } = b;
        if (b.when) {
            const scope = assetScope(b.asset, b.type, frame.values);
            const now = evaluate(b.when, scope);
            if (Number.isNaN(now)) return null;
            const breached = now !== 0;
            return {
                breached,
                cleared: b.clearWhen
                    ? holds(evaluate(b.clearWhen, scope))
                    : !breached,
                value: frame.values[b.key] ?? now,
            };
        }
        const raw = frame.values[b.key];
        if (raw === undefined) return null;
        let value = raw;
        if (rule.change) {
            const windowStart = frame.t - rule.change.overS * 1000;
            const last = b.samples.at(-1);
            // Time went backwards: a new source or a reload, so start again.
            if (last && last.t > frame.t) b.samples = [];
            b.samples.push({ t: frame.t, v: raw });
            while (b.samples.length > 1 && b.samples[1]!.t <= windowStart)
                b.samples.shift();
            const first = b.samples[0]!;
            if (first.t > windowStart) return null;
            value = raw - first.v;
        }
        const threshold = rule.threshold ?? 0;
        const clearAt = rule.clearAt ?? threshold;
        return {
            breached: rule.op === 'lt' ? value < threshold : value > threshold,
            cleared: rule.op === 'lt' ? value >= clearAt : value <= clearAt,
            value,
        };
    };

    return {
        evaluate(frame) {
            const raised: Alert[] = [];
            const cleared: Alert[] = [];
            for (const b of bindings) {
                const now = breach(b, frame);
                if (now === null) continue;
                if (b.alert) {
                    if (now.cleared) {
                        b.alert.clearedAt = frame.t;
                        cleared.push(b.alert);
                        b.alert = undefined;
                        b.breachedSince = undefined;
                    }
                    continue;
                }
                if (!now.breached) {
                    b.breachedSince = undefined;
                    continue;
                }
                b.breachedSince ??= frame.t;
                if (frame.t - b.breachedSince >= (b.rule.forS ?? 0) * 1000) {
                    const alert: Alert = {
                        id: `${b.rule.id}:${b.asset.id}:${frame.t}`,
                        ruleId: b.rule.id,
                        assetId: b.asset.id,
                        severity: b.rule.severity,
                        message: b.rule.message.replaceAll(
                            '{asset}',
                            b.asset.name,
                        ),
                        raisedAt: frame.t,
                        value: now.value,
                    };
                    b.alert = alert;
                    all.push(alert);
                    raised.push(alert);
                }
            }
            return { raised, cleared };
        },
        alerts: () => all,
        acknowledge(alertId, at) {
            const alert = all.find((a) => a.id === alertId);
            if (alert && alert.ackedAt === undefined) alert.ackedAt = at;
        },
    };
}

/** True if the alert was open at time `t`. */
export function activeAt(alert: Alert, t: number): boolean {
    return (
        alert.raisedAt <= t &&
        (alert.clearedAt === undefined || alert.clearedAt > t)
    );
}

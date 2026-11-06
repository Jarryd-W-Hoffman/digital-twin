import type {
    Alert,
    AssetDef,
    Frame,
    RuleDef,
    TwinDefinition,
} from './types.ts';

/**
 * Evaluates threshold rules against any frame, whichever source produced it,
 * so a live feed gets the same alerting as the simulator. A rule must stay
 * breached for `forS` before it raises, and clears only past `clearAt`, which
 * stops noise from chattering an alert open and closed.
 */

interface Binding {
    rule: RuleDef;
    asset: AssetDef;
    key: string;
    breachedSince?: number;
    alert?: Alert;
}

export interface RuleEngine {
    evaluate(frame: Frame): { raised: Alert[]; cleared: Alert[] };
    /** Every alert ever raised, oldest first. */
    alerts(): Alert[];
    acknowledge(alertId: string, at: number): void;
}

export function createRuleEngine(def: TwinDefinition): RuleEngine {
    const bindings: Binding[] = [];
    for (const rule of def.rules) {
        for (const asset of def.assets) {
            if (rule.asset === asset.id || rule.kind === asset.kind) {
                bindings.push({
                    rule,
                    asset,
                    key: `${asset.id}.${rule.metric}`,
                });
            }
        }
    }
    const all: Alert[] = [];

    return {
        evaluate(frame) {
            const raised: Alert[] = [];
            const cleared: Alert[] = [];
            for (const b of bindings) {
                const value = frame.values[b.key];
                if (value === undefined) continue;
                const { rule } = b;
                if (b.alert) {
                    const clearAt = rule.clearAt ?? rule.threshold;
                    if (
                        rule.op === 'gt' ? value <= clearAt : value >= clearAt
                    ) {
                        b.alert.clearedAt = frame.t;
                        cleared.push(b.alert);
                        b.alert = undefined;
                        b.breachedSince = undefined;
                    }
                    continue;
                }
                const breached =
                    rule.op === 'gt'
                        ? value > rule.threshold
                        : value < rule.threshold;
                if (!breached) {
                    b.breachedSince = undefined;
                    continue;
                }
                b.breachedSince ??= frame.t;
                if (frame.t - b.breachedSince >= (rule.forS ?? 0) * 1000) {
                    const alert: Alert = {
                        id: `${rule.id}:${b.asset.id}:${frame.t}`,
                        ruleId: rule.id,
                        assetId: b.asset.id,
                        severity: rule.severity,
                        message: rule.message.replaceAll(
                            '{asset}',
                            b.asset.name,
                        ),
                        raisedAt: frame.t,
                        value,
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

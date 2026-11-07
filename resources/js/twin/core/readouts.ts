import { evaluate, parseExpression } from './expr.ts';
import type { MetricDef } from './metrics.ts';
import type { ReadoutDef, Values } from './types.ts';

/**
 * A figure from the definition (a KPI, a panel item or chart), ready to
 * show: how to format it, and its value from any frame's values. Figures are
 * site-wide, so they read assets only as `{asset.metric}`.
 */
export interface Readout {
    metric: MetricDef;
    value: (values: Values) => number | undefined;
}

export function compileReadout(
    r: Omit<ReadoutDef, 'label'> & { label?: string },
    fallbackLabel = '',
): Readout {
    const node = parseExpression(r.value);
    const label = r.label ?? fallbackLabel;
    return {
        metric: {
            key: label,
            label,
            unit: r.unit ?? '',
            decimals: r.decimals ?? 0,
        },
        value: (values) => {
            const v = evaluate(node, {
                name: () => undefined,
                ref: (asset, metric) => values[`${asset}.${metric}`],
            });
            return Number.isNaN(v) ? undefined : v;
        },
    };
}

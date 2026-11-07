import { metricsFor } from './metrics.ts';
import type { Reading } from './protocol.ts';
import type { TelemetryMapping, TwinDefinition } from './types.ts';

/**
 * Turning MQTT messages into readings, per the twin's `telemetry` mappings.
 * Kept free of any MQTT client so it can be tested, and reused, on its own.
 */

/** Whether an MQTT topic filter (`+` one level, final `#` the rest) matches a topic. */
export function topicMatches(filter: string, topic: string): boolean {
    const want = filter.split('/');
    const have = topic.split('/');
    for (let i = 0; i < want.length; i++) {
        if (want[i] === '#') return true;
        if (i >= have.length) return false;
        if (want[i] !== '+' && want[i] !== have[i]) return false;
    }
    return want.length === have.length;
}

/**
 * The number in a payload: a bare number, or in JSON the value at `path`
 * (dotted keys), or the JSON number itself, or its `value`. Null otherwise.
 */
export function valueFrom(payload: string, path?: string): number | null {
    const text = payload.trim();
    let data: unknown;
    try {
        data = JSON.parse(text);
    } catch {
        return null;
    }
    if (path) {
        for (const key of path.split('.')) {
            data =
                typeof data === 'object' &&
                data !== null &&
                !Array.isArray(data)
                    ? (data as Record<string, unknown>)[key]
                    : undefined;
        }
    } else if (
        typeof data === 'object' &&
        data !== null &&
        !Array.isArray(data)
    ) {
        data = (data as Record<string, unknown>).value;
    }
    const value =
        typeof data === 'string' && data.trim() !== '' ? Number(data) : data;
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

export interface Telemetry {
    /** The distinct topic filters to subscribe to. */
    topics: string[];
    /** Readings from one message, and why any matching mapping gave none. */
    read(
        topic: string,
        payload: string,
    ): { readings: Reading[]; problems: string[] };
}

/** Compiles a twin's mappings, dropping (and reporting) those whose metric doesn't exist. */
export function compileTelemetry(
    def: TwinDefinition,
): Telemetry & { skipped: string[] } {
    const assets = new Map(def.assets.map((a) => [a.id, a]));
    const skipped: string[] = [];
    const mappings: TelemetryMapping[] = [];
    for (const m of def.telemetry ?? []) {
        const asset = assets.get(m.asset);
        if (
            asset &&
            metricsFor(def, asset).some((x) => x.key === m.metric && !x.hidden)
        ) {
            mappings.push(m);
        } else {
            skipped.push(
                `Telemetry ${m.topic}: ${m.asset} has no metric ${m.metric}`,
            );
        }
    }
    return {
        skipped,
        topics: [...new Set(mappings.map((m) => m.topic))],
        read(topic, payload) {
            const readings: Reading[] = [];
            const problems: string[] = [];
            for (const m of mappings) {
                if (!topicMatches(m.topic, topic)) continue;
                const raw = valueFrom(payload, m.path);
                if (raw === null) {
                    problems.push(
                        `${topic}: no number${m.path ? ` at ${m.path}` : ''} for ${m.asset}.${m.metric}`,
                    );
                    continue;
                }
                readings.push({
                    asset: m.asset,
                    metric: m.metric,
                    value: raw * (m.scale ?? 1) + (m.offset ?? 0),
                    ...(m.ttlS === undefined ? {} : { ttlS: m.ttlS }),
                });
            }
            return { readings, problems };
        },
    };
}

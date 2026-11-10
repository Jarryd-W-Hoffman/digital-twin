import { metricsFor } from './metrics.ts';
import type { Reading } from './protocol.ts';
import type { OpcUaMapping, TwinDefinition } from './types.ts';

/**
 * Turning OPC UA values into readings, per the twin's `opcua` mappings. Kept
 * free of any OPC UA client so it can be tested, and reused, on its own.
 */

/** Sampling interval when a mapping doesn't set one. */
export const DEFAULT_SAMPLING_MS = 1000;

/**
 * The number in an OPC UA value: a number, a boolean (1 or 0), a 64-bit
 * integer (a bigint, or node-opcua's [high, low] pair of 32-bit words), or
 * a numeric string. Null otherwise.
 */
export function numberFrom(value: unknown): number | null {
    if (typeof value === 'boolean') return value ? 1 : 0;
    if (typeof value === 'bigint') return Number(value);
    if (
        Array.isArray(value) &&
        value.length === 2 &&
        value.every((word) => Number.isInteger(word))
    ) {
        const [high, low] = value as [number, number];
        return high * 2 ** 32 + (low >>> 0);
    }
    const number =
        typeof value === 'string' && value.trim() !== ''
            ? Number(value)
            : value;
    return typeof number === 'number' && Number.isFinite(number)
        ? number
        : null;
}

/** A custom asset's setpoint range for a metric, if an operator may set it. */
function setpointOf(
    def: TwinDefinition,
    assetId: string,
    metric: string,
): { min: number; max: number } | undefined {
    const asset = def.assets.find((a) => a.id === assetId);
    if (asset?.kind !== 'custom') return undefined;
    return def.types
        ?.find((t) => t.id === asset.type)
        ?.metrics.find((m) => m.key === metric)?.setpoint;
}

/** One node to monitor on a server, and the metrics it feeds. */
export interface MonitoredNode {
    node: string;
    samplingMs: number;
    mappings: OpcUaMapping[];
}

export interface OpcUa {
    /** Per server name, the nodes to monitor. */
    servers: Map<string, MonitoredNode[]>;
    /** Mappings left out, and why. */
    skipped: string[];
    /** Readings from one node's new value, and why any mapping gave none. */
    read(
        server: string,
        node: string,
        value: unknown,
    ): { readings: Reading[]; problems: string[] };
    /**
     * Where an operator's setpoint goes: each written mapping of that metric,
     * with the setpoint clamped to its range and the mapping's scale and
     * offset undone, so the node gets what it would report back.
     */
    writes(asset: string, metric: string, value: number): OpcUaWrite[];
}

/** A value to write to one node. */
export interface OpcUaWrite {
    server: string;
    node: string;
    asset: string;
    metric: string;
    /** The setpoint as the operator asked for it, and as clamped. */
    requested: number;
    setpoint: number;
    /** What the node is sent. */
    value: number;
}

/**
 * Compiles a twin's mappings against the servers its project has, dropping
 * (and reporting) those whose server or metric doesn't exist. A node mapped
 * more than once is monitored once, at the fastest sampling asked for.
 */
export function compileOpcua(
    def: TwinDefinition,
    serverNames: Iterable<string>,
): OpcUa {
    const known = new Set(serverNames);
    const assets = new Map(def.assets.map((a) => [a.id, a]));
    const skipped: string[] = [];
    const servers = new Map<string, MonitoredNode[]>();
    for (const m of def.opcua ?? []) {
        const asset = assets.get(m.asset);
        if (!known.has(m.server)) {
            skipped.push(`OPC UA ${m.node}: no server named ${m.server}`);
            continue;
        }
        if (
            !asset ||
            !metricsFor(def, asset).some((x) => x.key === m.metric && !x.hidden)
        ) {
            skipped.push(
                `OPC UA ${m.node}: ${m.asset} has no metric ${m.metric}`,
            );
            continue;
        }
        const nodes = servers.get(m.server) ?? [];
        const samplingMs = m.samplingMs ?? DEFAULT_SAMPLING_MS;
        const existing = nodes.find((n) => n.node === m.node);
        if (existing) {
            existing.mappings.push(m);
            existing.samplingMs = Math.min(existing.samplingMs, samplingMs);
        } else {
            nodes.push({ node: m.node, samplingMs, mappings: [m] });
        }
        servers.set(m.server, nodes);
    }
    return {
        servers,
        skipped,
        writes(asset, metric, requested) {
            const range = setpointOf(def, asset, metric);
            if (!range || !Number.isFinite(requested)) return [];
            const setpoint = Math.min(
                range.max,
                Math.max(range.min, requested),
            );
            return [...servers.entries()].flatMap(([server, nodes]) =>
                nodes.flatMap((n) =>
                    n.mappings
                        .filter(
                            (m) =>
                                m.write === true &&
                                m.asset === asset &&
                                m.metric === metric,
                        )
                        .map((m) => ({
                            server,
                            node: n.node,
                            asset,
                            metric,
                            requested,
                            setpoint,
                            value:
                                (setpoint - (m.offset ?? 0)) / (m.scale ?? 1),
                        })),
                ),
            );
        },
        read(server, node, value) {
            const monitored = servers.get(server)?.find((n) => n.node === node);
            if (!monitored) return { readings: [], problems: [] };
            const raw = numberFrom(value);
            if (raw === null)
                return {
                    readings: [],
                    problems: [`OPC UA ${node} on ${server}: not a number`],
                };
            return {
                readings: monitored.mappings.map((m) => ({
                    asset: m.asset,
                    metric: m.metric,
                    value: raw * (m.scale ?? 1) + (m.offset ?? 0),
                    ...(m.ttlS === undefined ? {} : { ttlS: m.ttlS }),
                })),
                problems: [],
            };
        },
    };
}

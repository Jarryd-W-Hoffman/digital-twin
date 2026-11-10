import { router } from '@inertiajs/react';
import { useState } from 'react';
import { OpcUaNodeBrowser } from '@/components/opcua-browser';
import { update as updateWrites } from '@/routes/twins/opcua-writes';
import { store as storeVersion } from '@/routes/twins/versions';
import { metricsFor } from '@/twin/core/metrics';
import type { OpcUaMapping, TwinDefinition } from '@/twin/core/types';
import { validateDefinition } from '@/twin/core/validate';

type Row = {
    server: string;
    node: string;
    asset: string;
    metric: string;
    scale: string;
    offset: string;
    samplingMs: string;
    write: boolean;
};

/** Whether operators' setpoints go to the servers, and the latest that did. */
export type OpcUaWrites = {
    allowed: boolean;
    recent: {
        id: string;
        at: string;
        user: string | null;
        server: string;
        node: string;
        asset: string;
        metric: string;
        requested: number;
        setpoint: number;
        value: number;
        error: string | null;
    }[];
};

const text = (n: number | undefined) => (n === undefined ? '' : String(n));

const toRow = (m: OpcUaMapping): Row => ({
    server: m.server,
    node: m.node,
    asset: m.asset,
    metric: m.metric,
    scale: text(m.scale),
    offset: text(m.offset),
    samplingMs: text(m.samplingMs),
    write: m.write === true,
});

const toMapping = (r: Row): OpcUaMapping => ({
    server: r.server,
    node: r.node.trim(),
    asset: r.asset,
    metric: r.metric,
    ...(r.scale.trim() ? { scale: Number(r.scale) } : {}),
    ...(r.offset.trim() ? { offset: Number(r.offset) } : {}),
    ...(r.samplingMs.trim() ? { samplingMs: Number(r.samplingMs) } : {}),
    ...(r.write ? { write: true } : {}),
});

/** Whether an operator can set this metric, so it can be written back. */
const isSetpoint = (def: TwinDefinition, assetId: string, metric: string) => {
    const asset = def.assets.find((a) => a.id === assetId);
    return (
        asset?.kind === 'custom' &&
        def.types
            ?.find((t) => t.id === asset.type)
            ?.metrics.find((m) => m.key === metric)?.setpoint !== undefined
    );
};

/**
 * Which OPC UA nodes, on the project's servers, feed which asset metrics.
 * The twin's gateway monitors them; saving makes a new version. Mappings of
 * a setpoint can also be written back, once the twin allows it.
 */
export function OpcUaPanel({
    twinId,
    definition,
    servers,
    writes,
    gateway,
}: {
    twinId: string;
    definition: TwinDefinition;
    servers: string[];
    writes: OpcUaWrites;
    /** The gateway's socket and a ticket for it, to browse servers through. */
    gateway: { url: string; ticket: string } | null;
}) {
    const [rows, setRows] = useState<Row[]>(() =>
        (definition.opcua ?? []).map(toRow),
    );
    const [errors, setErrors] = useState<string[]>([]);
    const [saving, setSaving] = useState(false);
    const assets = definition.assets.filter((a) => a.kind !== 'site');
    const saved = JSON.stringify((definition.opcua ?? []).map(toRow));
    const dirty = JSON.stringify(rows) !== saved;

    const change = (i: number, patch: Partial<Row>) =>
        setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));

    const metricsOf = (assetId: string) => {
        const asset = definition.assets.find((a) => a.id === assetId);
        return asset
            ? metricsFor(definition, asset).filter((m) => !m.hidden)
            : [];
    };

    /** A new mapping, of the first asset's first metric until it's changed. */
    const add = (server: string, node: string) => {
        const asset = assets[0];
        setRows([
            ...rows,
            {
                server,
                node,
                asset: asset.id,
                metric: metricsOf(asset.id)[0]?.key ?? '',
                scale: '',
                offset: '',
                samplingMs: '',
                write: false,
            },
        ]);
    };

    const named = (assetId: string) =>
        definition.assets.find((a) => a.id === assetId)?.name ?? assetId;

    const allow = (allowed: boolean) => {
        if (
            allowed &&
            !window.confirm(
                "Let operators' setpoints change the OPC UA servers? Each setpoint mapped for writing will be written to the PLC.",
            )
        )
            return;
        router.put(
            updateWrites.url(twinId),
            { allowed },
            { preserveScroll: true, preserveState: true },
        );
    };

    const save = () => {
        const next = { ...definition, opcua: rows.map(toMapping) };
        const problems = validateDefinition(next);
        setErrors(problems);
        if (problems.length > 0) return;
        setSaving(true);
        router.post(
            storeVersion.url(twinId),
            {
                definition: next as unknown as Record<string, never>,
                note: 'Mapped OPC UA nodes',
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <section aria-labelledby="opcua-mappings-heading">
            <h3 id="opcua-mappings-heading">OPC UA</h3>
            <p className="muted hint">
                {servers.length === 0
                    ? "Nodes the gateway monitors on the project's OPC UA servers. Add a server on the project page first."
                    : "Nodes the gateway monitors on the project's OPC UA servers. Each new value is a reading: a number, or a boolean as 1 or 0."}
            </p>
            {rows.length > 0 && (
                <ul className="editor-layers" aria-label="OPC UA mappings">
                    {rows.map((row, i) => {
                        const metrics = metricsOf(row.asset);
                        return (
                            <li key={i} className="telemetry-row">
                                <label>
                                    <span>Server</span>
                                    <select
                                        value={row.server}
                                        onChange={(e) =>
                                            change(i, {
                                                server: e.target.value,
                                            })
                                        }
                                    >
                                        {!servers.includes(row.server) && (
                                            <option value={row.server}>
                                                {row.server} (missing)
                                            </option>
                                        )}
                                        {servers.map((s) => (
                                            <option key={s} value={s}>
                                                {s}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label>
                                    <span>Node</span>
                                    <input
                                        value={row.node}
                                        placeholder="ns=2;s=Store.TempC"
                                        onChange={(e) =>
                                            change(i, { node: e.target.value })
                                        }
                                    />
                                </label>
                                <label>
                                    <span>Asset</span>
                                    <select
                                        value={row.asset}
                                        onChange={(e) =>
                                            change(i, {
                                                asset: e.target.value,
                                                metric:
                                                    metricsOf(e.target.value)[0]
                                                        ?.key ?? '',
                                            })
                                        }
                                    >
                                        {assets.map((a) => (
                                            <option key={a.id} value={a.id}>
                                                {a.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label>
                                    <span>Metric</span>
                                    <select
                                        value={row.metric}
                                        onChange={(e) =>
                                            change(i, {
                                                metric: e.target.value,
                                            })
                                        }
                                    >
                                        {metrics.map((m) => (
                                            <option key={m.key} value={m.key}>
                                                {m.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label>
                                    <span>Scale</span>
                                    <input
                                        inputMode="decimal"
                                        value={row.scale}
                                        placeholder="1"
                                        onChange={(e) =>
                                            change(i, { scale: e.target.value })
                                        }
                                    />
                                </label>
                                <label>
                                    <span>Offset</span>
                                    <input
                                        inputMode="decimal"
                                        value={row.offset}
                                        placeholder="0"
                                        onChange={(e) =>
                                            change(i, {
                                                offset: e.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <label>
                                    <span>Sampling, ms</span>
                                    <input
                                        inputMode="numeric"
                                        value={row.samplingMs}
                                        placeholder="1000"
                                        onChange={(e) =>
                                            change(i, {
                                                samplingMs: e.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <label className="editor-check">
                                    <input
                                        type="checkbox"
                                        checked={row.write}
                                        disabled={
                                            !row.write &&
                                            !isSetpoint(
                                                definition,
                                                row.asset,
                                                row.metric,
                                            )
                                        }
                                        title="Only a custom type's setpoints can be written"
                                        onChange={(e) =>
                                            change(i, {
                                                write: e.target.checked,
                                            })
                                        }
                                    />
                                    <span>Write setpoints</span>
                                </label>
                                <button
                                    className="link"
                                    aria-label={`Remove OPC UA mapping ${row.node || i + 1}`}
                                    onClick={() =>
                                        setRows(rows.filter((_, j) => j !== i))
                                    }
                                >
                                    Remove
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
            <div className="editor-actions">
                <button
                    disabled={assets.length === 0 || servers.length === 0}
                    onClick={() => add(servers[0], '')}
                >
                    Add OPC UA mapping
                </button>
                <button
                    className="primary"
                    disabled={!dirty || saving}
                    onClick={save}
                >
                    Save OPC UA mappings
                </button>
            </div>
            {errors.length > 0 && (
                <ul className="editor-error" role="alert">
                    {errors.map((error) => (
                        <li key={error}>{error}</li>
                    ))}
                </ul>
            )}
            {assets.length > 0 && (
                <OpcUaNodeBrowser
                    servers={servers}
                    gateway={gateway}
                    onPick={(server, node) => add(server, node.node)}
                />
            )}
            <div role="group" aria-label="Writing setpoints">
                <h4>Writing setpoints</h4>
                <label className="editor-check">
                    <input
                        type="checkbox"
                        checked={writes.allowed}
                        onChange={(e) => allow(e.target.checked)}
                    />
                    <span>Write operators' setpoints to OPC UA</span>
                </label>
                <p className="muted hint">
                    {writes.allowed
                        ? 'When an operator applies a setpoint mapped for writing, the gateway writes it to the node, within the setpoint’s range.'
                        : 'Off: setpoints only change the model. Turn it on to have the gateway write them to the nodes mapped for writing.'}
                </p>
                {writes.recent.length > 0 && (
                    <ul
                        className="editor-layers"
                        aria-label="Recent OPC UA writes"
                    >
                        {writes.recent.map((w) => (
                            <li key={w.id}>
                                <strong>
                                    {named(w.asset)}{' '}
                                    {metricsOf(w.asset).find(
                                        (m) => m.key === w.metric,
                                    )?.label ?? w.metric}{' '}
                                    = {w.setpoint}
                                </strong>
                                {w.requested !== w.setpoint &&
                                    ` (asked for ${w.requested})`}
                                <span className="muted">
                                    {' '}
                                    → {w.node} on {w.server}
                                    {w.value !== w.setpoint && ` as ${w.value}`}
                                    , {w.user ?? 'someone'},{' '}
                                    {new Date(w.at).toLocaleString()}
                                </span>
                                {w.error !== null && (
                                    <span className="editor-error">
                                        {' '}
                                        Failed: {w.error}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

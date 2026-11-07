import { router } from '@inertiajs/react';
import { useState } from 'react';
import { store as storeVersion } from '@/routes/twins/versions';
import { metricsFor } from '@/twin/core/metrics';
import type { TelemetryMapping, TwinDefinition } from '@/twin/core/types';
import { validateDefinition } from '@/twin/core/validate';

type Row = {
    topic: string;
    asset: string;
    metric: string;
    path: string;
    scale: string;
    offset: string;
};

const toRow = (m: TelemetryMapping): Row => ({
    topic: m.topic,
    asset: m.asset,
    metric: m.metric,
    path: m.path ?? '',
    scale: m.scale === undefined ? '' : String(m.scale),
    offset: m.offset === undefined ? '' : String(m.offset),
});

const toMapping = (r: Row): TelemetryMapping => ({
    topic: r.topic.trim(),
    asset: r.asset,
    metric: r.metric,
    ...(r.path.trim() ? { path: r.path.trim() } : {}),
    ...(r.scale.trim() ? { scale: Number(r.scale) } : {}),
    ...(r.offset.trim() ? { offset: Number(r.offset) } : {}),
});

/**
 * Which MQTT topics feed which asset metrics. The twin's gateway subscribes
 * to them; saving makes a new version.
 */
export function TelemetryPanel({
    twinId,
    definition,
    topicPrefix,
}: {
    twinId: string;
    definition: TwinDefinition;
    topicPrefix: string;
}) {
    const [rows, setRows] = useState<Row[]>(() =>
        (definition.telemetry ?? []).map(toRow),
    );
    const [errors, setErrors] = useState<string[]>([]);
    const [saving, setSaving] = useState(false);
    const assets = definition.assets.filter((a) => a.kind !== 'site');
    const saved = JSON.stringify((definition.telemetry ?? []).map(toRow));
    const dirty = JSON.stringify(rows) !== saved;

    const change = (i: number, patch: Partial<Row>) =>
        setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));

    const metricsOf = (assetId: string) => {
        const asset = definition.assets.find((a) => a.id === assetId);
        return asset
            ? metricsFor(definition, asset).filter((m) => !m.hidden)
            : [];
    };

    const save = () => {
        const next = { ...definition, telemetry: rows.map(toMapping) };
        const problems = validateDefinition(next);
        setErrors(problems);
        if (problems.length > 0) return;
        setSaving(true);
        router.post(
            storeVersion.url(twinId),
            {
                definition: next as unknown as Record<string, never>,
                note: 'Mapped telemetry',
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <section aria-labelledby="telemetry-heading">
            <h3 id="telemetry-heading">Telemetry</h3>
            <p className="muted hint">
                MQTT topics the gateway subscribes to, below this project's{' '}
                <code>{topicPrefix}</code>, where its devices publish. A message
                is a number, or JSON with the number at <code>value</code> or
                the path given. <code>+</code> matches one topic level, a final{' '}
                <code>#</code> the rest.
            </p>
            {rows.length > 0 && (
                <ul className="editor-layers" aria-label="Telemetry mappings">
                    {rows.map((row, i) => {
                        const metrics = metricsOf(row.asset);
                        return (
                            <li key={i} className="telemetry-row">
                                <label>
                                    <span>Topic, below {topicPrefix}</span>
                                    <input
                                        value={row.topic}
                                        placeholder="site/store/temp"
                                        onChange={(e) =>
                                            change(i, { topic: e.target.value })
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
                                    <span>JSON path</span>
                                    <input
                                        value={row.path}
                                        placeholder="value"
                                        onChange={(e) =>
                                            change(i, { path: e.target.value })
                                        }
                                    />
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
                                <button
                                    className="link"
                                    aria-label={`Remove mapping ${row.topic || i + 1}`}
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
                    disabled={assets.length === 0}
                    onClick={() => {
                        const asset = assets[0];
                        setRows([
                            ...rows,
                            {
                                topic: '',
                                asset: asset.id,
                                metric: metricsOf(asset.id)[0]?.key ?? '',
                                path: '',
                                scale: '',
                                offset: '',
                            },
                        ]);
                    }}
                >
                    Add mapping
                </button>
                <button
                    className="primary"
                    disabled={!dirty || saving}
                    onClick={save}
                >
                    Save mappings
                </button>
            </div>
            {errors.length > 0 && (
                <ul className="editor-error" role="alert">
                    {errors.map((error) => (
                        <li key={error}>{error}</li>
                    ))}
                </ul>
            )}
        </section>
    );
}

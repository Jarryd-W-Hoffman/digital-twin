import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import TwinAnomalyExclusionController from '@/actions/App/Http/Controllers/TwinAnomalyExclusionController';
import TwinAnomalyMonitorController from '@/actions/App/Http/Controllers/TwinAnomalyMonitorController';
import { metricDefFor, metricsFor } from '@/twin/core/metrics';
import type { TwinDefinition } from '@/twin/core/types';

export type AnomalyMonitorSummary = {
    id: string;
    asset: string;
    metric: string;
    sensitivity: 'low' | 'medium' | 'high';
    severity: 'info' | 'warning' | 'critical';
    bands: boolean;
    flatlineHours: number | null;
    learnedDays: number;
    ready: boolean;
    /** Messages of the anomaly alerts it has open. */
    open: string[];
};

export type Anomalies = {
    monitors: AnomalyMonitorSummary[];
    exclusions: {
        id: string;
        startsAt: string;
        endsAt: string;
        note: string | null;
    }[];
    learningDays: number;
};

const SENSITIVITY = [
    { value: 'low', label: 'Low: only far from usual' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High: a little off usual' },
] as const;

const SEVERITY = [
    { value: 'info', label: 'Info' },
    { value: 'warning', label: 'Warning' },
    { value: 'critical', label: 'Critical' },
] as const;

const RELOAD = {
    preserveScroll: true,
    preserveState: true,
    only: ['anomalies'],
};

const when = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });

type Settings = {
    sensitivity: string;
    severity: string;
    bands: boolean;
    flatline_hours: string;
};

/**
 * Metrics watched for readings unusual for the time of day, learned from
 * their recorded history, or stuck at one value; and periods left out.
 * Everyone sees what's watched and what's unusual; editors change it.
 */
export function TwinAnomalies({
    twinId,
    definition,
    anomalies,
    canEdit,
    onClose,
}: {
    twinId: string;
    definition: TwinDefinition;
    anomalies: Anomalies;
    canEdit: boolean;
    onClose: () => void;
}) {
    const label = (assetId: string, metric: string) => {
        const asset = definition.assets.find((a) => a.id === assetId);
        const def = asset && metricDefFor(definition, asset, metric);
        return `${asset?.name ?? assetId} · ${def?.label ?? metric}`;
    };
    const watched = new Set(
        anomalies.monitors.map((m) => `${m.asset}.${m.metric}`),
    );
    // Every metric an asset shows; only those the gateway records can be learned.
    const choices = definition.assets.flatMap((asset) =>
        metricsFor(definition, asset)
            .filter((m) => !m.hidden && !watched.has(`${asset.id}.${m.key}`))
            .map((m) => ({
                value: `${asset.id}.${m.key}`,
                label: `${asset.name} · ${m.label}`,
            })),
    );

    const add = useForm({
        pair: '',
        sensitivity: 'medium',
        severity: 'warning',
        bands: true,
        flatline_hours: '',
    });
    const exclude = useForm({ starts_at: '', ends_at: '', note: '' });

    return (
        <aside className="panel editor" aria-label="Anomalies">
            <div className="editor-head">
                <h2>Anomalies</h2>
                <button
                    className="ghost"
                    onClick={onClose}
                    aria-label="Close anomalies"
                >
                    ✕
                </button>
            </div>

            <section>
                <p className="muted hint">
                    A watched metric learns what's usual for each hour of the
                    day, on weekdays and weekends, from its last four weeks of
                    readings. When the last half hour is far from that, or the
                    reading hasn't changed for hours, it raises an alert,
                    through the alert log and the project's channels. The usual
                    range shows behind its chart under Recorded. Only readings
                    the gateway records, from MQTT or OPC UA, are learned from.
                </p>
            </section>

            <section aria-label="Watched">
                <h3>Watched</h3>
                {anomalies.monitors.length === 0 ? (
                    <p className="editor-hint">No metrics watched yet.</p>
                ) : (
                    <ul className="view-list">
                        {anomalies.monitors.map((monitor) => (
                            <MonitorRow
                                key={monitor.id}
                                twinId={twinId}
                                monitor={monitor}
                                name={label(monitor.asset, monitor.metric)}
                                learningDays={anomalies.learningDays}
                                canEdit={canEdit}
                            />
                        ))}
                    </ul>
                )}
            </section>

            {canEdit && (
                <section>
                    <h3>Watch a metric</h3>
                    {choices.length === 0 ? (
                        <p className="editor-hint">Every metric is watched.</p>
                    ) : (
                        <form
                            className="editor-form"
                            onSubmit={(e) => {
                                e.preventDefault();
                                const [asset, metric] =
                                    add.data.pair.split('.');
                                add.transform((data) => ({
                                    ...data,
                                    asset,
                                    metric,
                                    flatline_hours: data.flatline_hours || null,
                                }));
                                add.submit(
                                    TwinAnomalyMonitorController.store(twinId),
                                    {
                                        ...RELOAD,
                                        onSuccess: () => add.reset('pair'),
                                    },
                                );
                            }}
                        >
                            <label className="editor-field">
                                <span>Metric</span>
                                <select
                                    required
                                    value={add.data.pair}
                                    onChange={(e) =>
                                        add.setData('pair', e.target.value)
                                    }
                                >
                                    <option value="">Choose…</option>
                                    {choices.map((c) => (
                                        <option key={c.value} value={c.value}>
                                            {c.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <SettingsFields
                                data={add.data}
                                set={(key, value) =>
                                    add.setData((d) => ({ ...d, [key]: value }))
                                }
                            />
                            {Object.values(add.errors)[0] && (
                                <p className="editor-error" role="alert">
                                    {Object.values(add.errors)[0]}
                                </p>
                            )}
                            <div className="editor-actions">
                                <button
                                    type="submit"
                                    className="primary"
                                    disabled={add.processing}
                                >
                                    Watch
                                </button>
                            </div>
                        </form>
                    )}
                </section>
            )}

            <section aria-label="Left out">
                <h3>Left out</h3>
                <p className="muted hint">
                    Periods that weren't normal, such as a shutdown or
                    commissioning: not learned from, and not checked while they
                    last.
                </p>
                {anomalies.exclusions.length === 0 ? (
                    <p className="editor-hint">Nothing left out.</p>
                ) : (
                    <ul className="view-list">
                        {anomalies.exclusions.map((x) => (
                            <li
                                key={x.id}
                                className="view-row"
                                aria-label={x.note ?? 'Left out'}
                            >
                                <div className="view-open">
                                    <span className="view-name">
                                        {x.note ?? 'Left out'}
                                    </span>
                                    <span className="issue-meta">
                                        {when(x.startsAt)} to {when(x.endsAt)}
                                    </span>
                                </div>
                                {canEdit && (
                                    <div className="view-actions">
                                        <button
                                            className="ghost"
                                            onClick={() =>
                                                router.delete(
                                                    TwinAnomalyExclusionController.destroy.url(
                                                        [twinId, x.id],
                                                    ),
                                                    RELOAD,
                                                )
                                            }
                                        >
                                            Count it again
                                        </button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {canEdit && (
                    <form
                        className="editor-form"
                        onSubmit={(e) => {
                            e.preventDefault();
                            exclude.transform((data) => ({
                                ...data,
                                starts_at: new Date(
                                    data.starts_at,
                                ).toISOString(),
                                ends_at: new Date(data.ends_at).toISOString(),
                            }));
                            exclude.submit(
                                TwinAnomalyExclusionController.store(twinId),
                                {
                                    ...RELOAD,
                                    onSuccess: () => exclude.reset(),
                                },
                            );
                        }}
                    >
                        <label className="editor-field">
                            <span>From</span>
                            <input
                                type="datetime-local"
                                required
                                value={exclude.data.starts_at}
                                onChange={(e) =>
                                    exclude.setData('starts_at', e.target.value)
                                }
                            />
                        </label>
                        <label className="editor-field">
                            <span>To</span>
                            <input
                                type="datetime-local"
                                required
                                value={exclude.data.ends_at}
                                onChange={(e) =>
                                    exclude.setData('ends_at', e.target.value)
                                }
                            />
                        </label>
                        <label className="editor-field">
                            <span>Why</span>
                            <input
                                maxLength={200}
                                placeholder="Chiller replaced"
                                value={exclude.data.note}
                                onChange={(e) =>
                                    exclude.setData('note', e.target.value)
                                }
                            />
                        </label>
                        {Object.values(exclude.errors)[0] && (
                            <p className="editor-error" role="alert">
                                {Object.values(exclude.errors)[0]}
                            </p>
                        )}
                        <div className="editor-actions">
                            <button type="submit" disabled={exclude.processing}>
                                Leave out
                            </button>
                        </div>
                    </form>
                )}
            </section>
        </aside>
    );
}

function SettingsFields({
    data,
    set,
}: {
    data: Settings;
    set: <K extends keyof Settings>(key: K, value: Settings[K]) => void;
}) {
    return (
        <>
            <label className="editor-check">
                <input
                    type="checkbox"
                    checked={data.bands}
                    onChange={(e) => set('bands', e.target.checked)}
                />
                <span>Alert when unusual for the time of day</span>
            </label>
            <label className="editor-field">
                <span>Sensitivity</span>
                <select
                    value={data.sensitivity}
                    disabled={!data.bands}
                    onChange={(e) => set('sensitivity', e.target.value)}
                >
                    {SENSITIVITY.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="editor-field">
                <span>Alert when unchanged for (hours)</span>
                <input
                    type="number"
                    min={1}
                    max={168}
                    placeholder="Off"
                    value={data.flatline_hours}
                    onChange={(e) => set('flatline_hours', e.target.value)}
                />
            </label>
            <label className="editor-field">
                <span>Severity</span>
                <select
                    value={data.severity}
                    onChange={(e) => set('severity', e.target.value)}
                >
                    {SEVERITY.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </select>
            </label>
        </>
    );
}

function MonitorRow({
    twinId,
    monitor,
    name,
    learningDays,
    canEdit,
}: {
    twinId: string;
    monitor: AnomalyMonitorSummary;
    name: string;
    learningDays: number;
    canEdit: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const form = useForm<Settings>({
        sensitivity: monitor.sensitivity,
        severity: monitor.severity,
        bands: monitor.bands,
        flatline_hours: monitor.flatlineHours?.toString() ?? '',
    });
    const ids: [string, string] = [twinId, monitor.id];
    const checks = [
        monitor.bands &&
            (monitor.ready
                ? `${monitor.sensitivity} sensitivity`
                : `learning, ${Math.floor(monitor.learnedDays)} of ${learningDays} days`),
        monitor.flatlineHours !== null &&
            `unchanged for ${monitor.flatlineHours} h`,
    ].filter(Boolean);

    return (
        <li className="view-row" aria-label={name}>
            <div className="view-open">
                <span className="view-name">{name}</span>
                <span className="issue-meta">
                    {checks.join(' · ')} · {monitor.severity}
                </span>
                {monitor.open.map((message) => (
                    <span key={message} className="editor-error" role="status">
                        {message}
                    </span>
                ))}
            </div>
            {canEdit && !editing && (
                <div className="view-actions">
                    <button className="ghost" onClick={() => setEditing(true)}>
                        Change
                    </button>
                    <button
                        className="ghost"
                        onClick={() =>
                            confirm(
                                `Stop watching ${name}? What it has open is cleared.`,
                            ) &&
                            router.delete(
                                TwinAnomalyMonitorController.destroy.url(ids),
                                RELOAD,
                            )
                        }
                    >
                        Stop watching
                    </button>
                </div>
            )}
            {editing && (
                <form
                    className="editor-form"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            flatline_hours: data.flatline_hours || null,
                        }));
                        form.submit(TwinAnomalyMonitorController.update(ids), {
                            ...RELOAD,
                            onSuccess: () => setEditing(false),
                        });
                    }}
                >
                    <SettingsFields
                        data={form.data}
                        set={(key, value) =>
                            form.setData((d) => ({ ...d, [key]: value }))
                        }
                    />
                    {Object.values(form.errors)[0] && (
                        <p className="editor-error" role="alert">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                    <div className="editor-actions">
                        <button
                            type="submit"
                            className="primary"
                            disabled={form.processing}
                        >
                            Save
                        </button>
                        <button
                            type="button"
                            className="ghost"
                            onClick={() => setEditing(false)}
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            )}
        </li>
    );
}

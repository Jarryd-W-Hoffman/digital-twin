import { useEffect, useMemo, useState } from 'react';
import { stateLabelOf } from '../core/behaviour.ts';
import {
    formatMetric,
    METRICS,
    metricDefFor,
    metricsFor,
    typeOf,
    type MetricDef,
} from '../core/metrics.ts';
import { compileReadout } from '../core/readouts.ts';
import type { AssetDef, PanelDef, TypeMetricDef } from '../core/types.ts';
import {
    getHistory,
    alertsAt,
    useTwin,
    type Recorded,
} from '../state/store.ts';
import { clock, dateTime, kw } from './format.ts';
import { LineChart } from './LineChart.tsx';
import { SeverityBadge, StatusBadge } from './StatusBadge.tsx';

const SPARK_WINDOW_MS = 6 * 3_600_000;
const LINE_COLOR = '#3987e5';

export function Inspector() {
    const def = useTwin((s) => s.def);
    const selectedId = useTwin((s) => s.selectedId);
    const asset = def?.assets.find((a) => a.id === selectedId);
    if (!def) return null;
    return asset && asset.kind !== 'site' ? (
        <AssetDetail asset={asset} />
    ) : (
        <SiteOverview />
    );
}

function SiteOverview() {
    const def = useTwin((s) => s.def)!;
    const view = useTwin((s) => s.view);
    const select = useTwin((s) => s.select);
    const historyVersion = useTwin((s) => s.historyVersion);
    const v = view?.values ?? {};
    const stores = def.assets.filter((a) => a.kind === 'coldstore');
    const site = def.assets.find((a) => a.kind === 'site');
    const metrics = site
        ? METRICS.site.filter(
              (m) =>
                  !m.hidden && m.key !== 'gensetKw' && m.key !== 'unservedKw',
          )
        : [];
    const loadSeries = useMemo(
        () => getHistory().series('site.loadKw', -Infinity, Infinity, 200),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [historyVersion],
    );
    return (
        <div className="inspector">
            <div className="inspector-head">
                <h2>{def.name}</h2>
                <p className="muted">{def.description}</p>
            </div>
            <h3>Site load</h3>
            <LineChart
                series={[
                    {
                        key: 'load',
                        label: 'Load',
                        color: LINE_COLOR,
                        points: loadSeries,
                    },
                ]}
                height={110}
                formatValue={(x) => kw(x)}
                formatTime={(t) => clock(t, def.utcOffsetHours)}
                marker={view?.t}
                label="Site load over time"
            />
            <h3>Cold stores</h3>
            <div className="store-list">
                {stores.map((s) => (
                    <button
                        key={s.id}
                        className="store-row"
                        onClick={() => select(s.id, true)}
                    >
                        <span>{s.name}</span>
                        <strong>
                            {(v[`${s.id}.tempC`] ?? 0).toFixed(1)} °C
                        </strong>
                        <span className="muted">
                            limit {s.params?.limitC} °C
                        </span>
                    </button>
                ))}
            </div>
            {site && (
                <>
                    <h3>Energy and cost</h3>
                    <dl className="readings">
                        {metrics.map((m) => (
                            <div key={m.key}>
                                <dt>{m.label}</dt>
                                <dd>{formatMetric(m, v[`site.${m.key}`])}</dd>
                            </div>
                        ))}
                    </dl>
                </>
            )}
            {def.panels?.map((panel) => (
                <DefinedPanel key={panel.title} panel={panel} />
            ))}
            <p className="muted hint">
                Select a building in the scene, or an asset on the left.
            </p>
        </div>
    );
}

/** A section of the site summary declared in the definition. */
function DefinedPanel({ panel }: { panel: PanelDef }) {
    const def = useTwin((s) => s.def)!;
    const view = useTwin((s) => s.view);
    const historyVersion = useTwin((s) => s.historyVersion);
    const v = view?.values ?? {};
    const items = useMemo(
        () => (panel.items ?? []).map((i) => compileReadout(i)),
        [panel],
    );
    const chart = useMemo(
        () => (panel.chart ? compileReadout(panel.chart, panel.title) : null),
        [panel],
    );
    const points = useMemo(
        () =>
            chart
                ? getHistory().seriesOf(chart.value, -Infinity, Infinity, 200)
                : [],
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [historyVersion, chart],
    );
    return (
        <section aria-label={panel.title}>
            <h3>{panel.title}</h3>
            {chart && (
                <LineChart
                    series={[
                        {
                            key: 'chart',
                            label: chart.metric.label,
                            color: LINE_COLOR,
                            points,
                        },
                    ]}
                    height={90}
                    formatValue={(x) => formatMetric(chart.metric, x)}
                    formatTime={(t) => clock(t, def.utcOffsetHours)}
                    marker={view?.t}
                    label={`${chart.metric.label} over time`}
                />
            )}
            {items.length > 0 && (
                <dl className="readings">
                    {items.map((i) => (
                        <div key={i.metric.label}>
                            <dt>{i.metric.label}</dt>
                            <dd>{formatMetric(i.metric, i.value(v))}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </section>
    );
}

function AssetDetail({ asset }: { asset: AssetDef }) {
    const def = useTwin((s) => s.def)!;
    const view = useTwin((s) => s.view);
    const measured = useTwin((s) => s.measured);
    const historyVersion = useTwin((s) => s.historyVersion);
    const alerts = useTwin((s) => s.alerts);
    const select = useTwin((s) => s.select);
    const v = view?.values ?? {};
    const metrics = metricsFor(def, asset).filter((m) => !m.hidden);
    const open = alertsAt(alerts, view?.t).filter(
        (a) => a.assetId === asset.id,
    );

    const series = useMemo(() => {
        const h = getHistory();
        const last = h.last()?.t ?? 0;
        return Object.fromEntries(
            metrics.map((m) => [
                m.key,
                h.series(
                    `${asset.id}.${m.key}`,
                    last - SPARK_WINDOW_MS,
                    last,
                    90,
                ),
            ]),
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [historyVersion, asset.id]);

    const name = (id: string) => def.assets.find((a) => a.id === id);
    const fedBy = asset.fedBy ? name(asset.fedBy) : undefined;
    const feeds = def.assets.filter((a) => a.fedBy === asset.id);
    const cools = (asset.cools ?? []).map(name).filter(Boolean) as AssetDef[];
    const cooledBy = def.assets.filter((a) => a.cools?.includes(asset.id));
    const stateLabel = stateLabelOf(def, asset, v);

    return (
        <div className="inspector">
            <div className="inspector-head">
                <button className="ghost back" onClick={() => select(null)}>
                    ← Site
                </button>
                <h2>{asset.name}</h2>
                <div className="inspector-sub">
                    <StatusBadge state={v[`${asset.id}.state`]} />
                    <span className="muted">
                        {typeOf(def, asset)?.name ?? asset.kind}
                        {stateLabel && ` · ${stateLabel}`}
                    </span>
                </div>
            </div>

            {open.map((a) => (
                <div key={a.id} className={`callout is-${a.severity}`}>
                    <SeverityBadge severity={a.severity} />
                    <span>{a.message}</span>
                </div>
            ))}

            <Controls asset={asset} />

            <h3>Readings</h3>
            <div className="metric-list">
                {metrics.map((m) => (
                    <MetricRow
                        key={m.key}
                        metric={m}
                        value={v[`${asset.id}.${m.key}`]}
                        measured={measured.includes(`${asset.id}.${m.key}`)}
                        points={series[m.key] ?? []}
                        marker={view?.t}
                        offset={def.utcOffsetHours}
                    />
                ))}
            </div>

            <RecordedReadings asset={asset} metrics={metrics} />

            {(fedBy ||
                feeds.length > 0 ||
                cools.length > 0 ||
                cooledBy.length > 0) && (
                <>
                    <h3>Connected to</h3>
                    <div className="links">
                        {fedBy && <LinkGroup label="Fed by" assets={[fedBy]} />}
                        {feeds.length > 0 && (
                            <LinkGroup label="Feeds" assets={feeds} />
                        )}
                        {cools.length > 0 && (
                            <LinkGroup label="Cools" assets={cools} />
                        )}
                        {cooledBy.length > 0 && (
                            <LinkGroup label="Cooled by" assets={cooledBy} />
                        )}
                    </div>
                </>
            )}
        </div>
    );
}

const RANGES = [
    { label: '24 h', ms: 86_400_000 },
    { label: '7 days', ms: 7 * 86_400_000 },
    { label: '30 days', ms: 30 * 86_400_000 },
];

/**
 * Readings sensors actually reported, as the control plane recorded them, over
 * days rather than the live window. Real time, not the simulated clock.
 */
function RecordedReadings({
    asset,
    metrics,
}: {
    asset: AssetDef;
    metrics: MetricDef[];
}) {
    const recorded = useTwin((s) => s.recorded);
    const offset = useTwin((s) => s.def?.utcOffsetHours ?? 0);
    const [metricKey, setMetricKey] = useState(metrics[0]?.key ?? '');
    const [range, setRange] = useState(RANGES[0].ms);
    const [result, setResult] = useState<
        { key: string; data: Recorded } | { key: string; error: string } | null
    >(null);
    const metric = metrics.find((m) => m.key === metricKey) ?? metrics[0];
    const key = `${asset.id}.${metric?.key}.${range}`;

    useEffect(() => {
        if (!recorded || !metric) return;
        let current = true;
        const to = Date.now();
        recorded(asset.id, metric.key, to - range, to).then(
            (data) => current && setResult({ key, data }),
            (error: unknown) =>
                current &&
                setResult({
                    key,
                    error:
                        error instanceof Error
                            ? error.message
                            : 'Could not load the history',
                }),
        );
        return () => {
            current = false;
        };
    }, [recorded, asset.id, metric, range, key]);

    if (!recorded || !metric) return null;
    const shown = result?.key === key ? result : null;

    return (
        <section aria-label="Recorded readings">
            <h3>Recorded</h3>
            <div className="recorded-controls">
                <select
                    aria-label="Recorded metric"
                    value={metric.key}
                    onChange={(e) => setMetricKey(e.target.value)}
                >
                    {metrics.map((m) => (
                        <option key={m.key} value={m.key}>
                            {m.label}
                        </option>
                    ))}
                </select>
                <div className="segmented" role="group" aria-label="Range">
                    {RANGES.map((r) => (
                        <button
                            key={r.ms}
                            className={r.ms === range ? 'on' : ''}
                            aria-pressed={r.ms === range}
                            onClick={() => setRange(r.ms)}
                        >
                            {r.label}
                        </button>
                    ))}
                </div>
            </div>
            {shown === null ? (
                <p className="muted hint">Loading…</p>
            ) : 'error' in shown ? (
                <p className="muted hint">{shown.error}</p>
            ) : shown.data.points.length === 0 ? (
                <p className="muted hint">
                    No readings recorded in this range. Map an MQTT topic to
                    this metric, or post to the gateway's /ingest.
                </p>
            ) : (
                <LineChart
                    series={[
                        {
                            key: 'mean',
                            label: metric.label,
                            color: LINE_COLOR,
                            points: shown.data.points.map((p) => ({
                                t: p.t,
                                v: p.mean,
                            })),
                        },
                    ]}
                    height={120}
                    formatValue={(x) => formatMetric(metric, x)}
                    formatTime={(t) => dateTime(t, offset)}
                    label={`${metric.label}, recorded`}
                />
            )}
        </section>
    );
}

/** A custom type's setpoint: step it within range, then apply. */
function SetpointRow({
    asset,
    metric,
    format,
    value,
    disabled,
}: {
    asset: AssetDef;
    metric: TypeMetricDef;
    format: MetricDef | undefined;
    value: number;
    disabled: boolean;
}) {
    const send = useTwin((s) => s.send);
    const [draft, setDraft] = useState<number | null>(null);
    const { min, max, step = 1 } = metric.setpoint!;
    const shown = draft ?? value;
    const label = metric.label ?? metric.key;
    const id = `setpoint-${metric.key}`;
    const move = (by: number) =>
        setDraft(Math.min(max, Math.max(min, shown + by)));
    return (
        <div className="control-row">
            <label htmlFor={id}>{label}</label>
            <div className="stepper">
                <button
                    aria-label={`Lower ${label}`}
                    disabled={disabled || shown <= min}
                    onClick={() => move(-step)}
                >
                    −
                </button>
                <output id={id}>{formatMetric(format, shown)}</output>
                <button
                    aria-label={`Raise ${label}`}
                    disabled={disabled || shown >= max}
                    onClick={() => move(step)}
                >
                    +
                </button>
            </div>
            <button
                className="primary"
                disabled={disabled || draft === null || draft === value}
                onClick={() => {
                    send({
                        type: 'setpoint',
                        asset: asset.id,
                        metric: metric.key,
                        value: shown,
                    });
                    setDraft(null);
                }}
            >
                Apply
            </button>
        </div>
    );
}

function LinkGroup({ label, assets }: { label: string; assets: AssetDef[] }) {
    const select = useTwin((s) => s.select);
    return (
        <div className="link-group">
            <span className="muted">{label}</span>
            <div>
                {assets.map((a) => (
                    <button
                        key={a.id}
                        className="link"
                        onClick={() => select(a.id, true)}
                    >
                        {a.name}
                    </button>
                ))}
            </div>
        </div>
    );
}

function MetricRow({
    metric,
    value,
    measured,
    points,
    marker,
    offset,
}: {
    metric: MetricDef;
    value: number | undefined;
    measured: boolean;
    points: { t: number; v: number }[];
    marker: number | undefined;
    offset: number;
}) {
    return (
        <div className="metric">
            <div className="metric-text">
                <span className="metric-label">
                    {metric.label}
                    {measured && (
                        <span
                            className="measured"
                            title="From a sensor, not the model"
                        >
                            measured
                        </span>
                    )}
                </span>
                <span className="metric-value">
                    {formatMetric(metric, value)}
                </span>
            </div>
            <LineChart
                series={[
                    {
                        key: metric.key,
                        label: metric.label,
                        color: LINE_COLOR,
                        points,
                    },
                ]}
                height={34}
                axes={false}
                formatValue={(x) => formatMetric(metric, x)}
                formatTime={(t) => clock(t, offset)}
                marker={marker}
                label={`${metric.label}, last 6 hours`}
            />
        </div>
    );
}

function Controls({ asset }: { asset: AssetDef }) {
    const def = useTwin((s) => s.def);
    const view = useTwin((s) => s.view);
    const send = useTwin((s) => s.send);
    const replayT = useTwin((s) => s.replayT);
    const v = view?.values ?? {};
    const [draft, setDraft] = useState<number | null>(null);
    const canOperate = useTwin((s) => s.canOperate);
    const disabled = replayT !== null || !canOperate;

    if (asset.kind === 'custom') {
        const setpoints = (typeOf(def, asset)?.metrics ?? []).filter(
            (m) => m.setpoint,
        );
        return (
            <>
                {setpoints.map((m) => (
                    <SetpointRow
                        key={m.key}
                        asset={asset}
                        metric={m}
                        format={metricDefFor(def, asset, m.key)}
                        value={v[`${asset.id}.${m.key}`] ?? 0}
                        disabled={disabled}
                    />
                ))}
            </>
        );
    }
    if (asset.kind === 'coldstore') {
        const setpoint = v[`${asset.id}.setpointC`] ?? 0;
        const shown = draft ?? setpoint;
        return (
            <div className="control-row">
                <label htmlFor="setpoint">Setpoint</label>
                <div className="stepper">
                    <button
                        aria-label="Lower setpoint"
                        disabled={disabled}
                        onClick={() => setDraft(shown - 0.5)}
                    >
                        −
                    </button>
                    <output id="setpoint">{shown.toFixed(1)} °C</output>
                    <button
                        aria-label="Raise setpoint"
                        disabled={disabled}
                        onClick={() => setDraft(shown + 0.5)}
                    >
                        +
                    </button>
                </div>
                <button
                    className="primary"
                    disabled={disabled || draft === null || draft === setpoint}
                    onClick={() => {
                        send({
                            type: 'setpoint',
                            asset: asset.id,
                            value: shown,
                        });
                        setDraft(null);
                    }}
                >
                    Apply
                </button>
            </div>
        );
    }
    if (asset.kind === 'chiller') {
        const on = (v[`${asset.id}.maintenance`] ?? 0) > 0;
        return (
            <div className="control-row">
                <span>Service</span>
                <button
                    disabled={disabled}
                    className={on ? 'primary' : ''}
                    onClick={() =>
                        send({ type: 'maintenance', asset: asset.id, on: !on })
                    }
                >
                    {on ? 'Return to service' : 'Take out of service'}
                </button>
            </div>
        );
    }
    return null;
}

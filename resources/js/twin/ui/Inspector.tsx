import { useMemo, useState } from 'react';
import {
    formatMetric,
    METRICS,
    metricsFor,
    type MetricDef,
} from '../core/metrics.ts';
import type { AssetDef } from '../core/types.ts';
import { getHistory, alertsAt, useTwin } from '../state/store.ts';
import { clock, kw } from './format.ts';
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
            <p className="muted hint">
                Select a building in the scene, or an asset on the left.
            </p>
        </div>
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

    return (
        <div className="inspector">
            <div className="inspector-head">
                <button className="ghost back" onClick={() => select(null)}>
                    ← Site
                </button>
                <h2>{asset.name}</h2>
                <div className="inspector-sub">
                    <StatusBadge state={v[`${asset.id}.state`]} />
                    <span className="muted">{asset.kind}</span>
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
    const view = useTwin((s) => s.view);
    const send = useTwin((s) => s.send);
    const replayT = useTwin((s) => s.replayT);
    const v = view?.values ?? {};
    const [draft, setDraft] = useState<number | null>(null);
    const canOperate = useTwin((s) => s.canOperate);
    const disabled = replayT !== null || !canOperate;

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

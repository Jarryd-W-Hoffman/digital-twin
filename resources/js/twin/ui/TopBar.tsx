import { useEffect, useRef, useState, type ReactNode } from 'react';
import { alertsAt, useTwin } from '../state/store.ts';
import { clock, kw } from './format.ts';

interface TwinEntry {
    id: string;
    name: string;
}

function Kpi({
    label,
    value,
    note,
}: {
    label: string;
    value: string;
    note?: string;
}) {
    return (
        <div className="kpi">
            <span className="kpi-label">{label}</span>
            <span className="kpi-value">{value}</span>
            {note && <span className="kpi-note">{note}</span>}
        </div>
    );
}

export function TopBar({ header }: { header?: ReactNode }) {
    const embedded = header !== undefined;
    const def = useTwin((s) => s.def);
    const view = useTwin((s) => s.view);
    const status = useTwin((s) => s.status);
    const sourceLabel = useTwin((s) => s.sourceLabel);
    const sourceKind = useTwin((s) => s.sourceKind);
    const alerts = useTwin((s) => s.alerts);
    const replayT = useTwin((s) => s.replayT);
    const measured = useTwin((s) => s.measured);
    const loadTwinFile = useTwin((s) => s.loadTwinFile);
    const loadTwinById = useTwin((s) => s.loadTwinById);
    const setTab = useTwin((s) => s.setTab);
    const [twins, setTwins] = useState<TwinEntry[]>([]);
    const fileInput = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (embedded) return;
        fetch(`/samples/index.json`)
            .then((r) => (r.ok ? (r.json() as Promise<TwinEntry[]>) : []))
            .then(setTwins)
            .catch(() => setTwins([]));
    }, [embedded]);

    const v = view?.values ?? {};
    const active = alertsAt(alerts, view?.t);
    const critical = active.filter((a) => a.severity === 'critical').length;
    const battery = def?.assets.find((a) => a.kind === 'battery');
    const stores = def?.assets.filter((a) => a.kind === 'coldstore') ?? [];
    const worstMargin = stores.reduce((worst, s) => {
        const margin = (s.params?.limitC ?? 0) - (v[`${s.id}.tempC`] ?? 0);
        return Math.min(worst, margin);
    }, Infinity);
    const islanded = v['site.islanded'] === 1;

    return (
        <header className="topbar panel">
            <div className="brand">
                <svg
                    width="20"
                    height="20"
                    viewBox="0 0 32 32"
                    aria-hidden="true"
                >
                    <circle
                        cx="16"
                        cy="16"
                        r="12"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.4"
                    />
                    <ellipse
                        cx="16"
                        cy="16"
                        rx="5.500"
                        ry="12"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.4"
                    />
                </svg>
                <span className="brand-name">Meridian</span>
            </div>
            {embedded ? (
                <div className="twin-select">{header}</div>
            ) : (
                <div className="twin-select">
                    {sourceKind === 'simulator' && twins.length > 0 ? (
                        <select
                            aria-label="Twin"
                            value={
                                twins.some((t) => t.id === def?.id)
                                    ? def?.id
                                    : ''
                            }
                            onChange={(e) => void loadTwinById(e.target.value)}
                        >
                            {!twins.some((t) => t.id === def?.id) && (
                                <option value="">
                                    {def?.name ?? 'Loading'}
                                </option>
                            )}
                            {twins.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <span className="twin-name">
                            {def?.name ?? 'Connecting'}
                        </span>
                    )}
                    <button
                        className="ghost"
                        onClick={() => fileInput.current?.click()}
                        title="Load a twin definition from a JSON file"
                    >
                        Load file
                    </button>
                    <input
                        ref={fileInput}
                        type="file"
                        accept="application/json,.json"
                        hidden
                        data-testid="twin-file"
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            if (file) void loadTwinFile(file);
                            e.target.value = '';
                        }}
                    />
                </div>
            )}

            <div className="kpis">
                <Kpi label="Site load" value={kw(v['site.loadKw'])} />
                <Kpi label="Solar" value={kw(v['site.solarKw'])} />
                <Kpi
                    label={
                        islanded
                            ? 'Grid'
                            : (v['site.gridKw'] ?? 0) < 0
                              ? 'Export'
                              : 'Grid import'
                    }
                    value={
                        islanded ? 'Lost' : kw(Math.abs(v['site.gridKw'] ?? 0))
                    }
                />
                {battery && (
                    <Kpi
                        label="Battery"
                        value={`${Math.round(v[`${battery.id}.socPct`] ?? 0)}%`}
                    />
                )}
                {Number.isFinite(worstMargin) && (
                    <Kpi
                        label="Tightest store"
                        value={
                            worstMargin < 0
                                ? 'Over limit'
                                : `${worstMargin.toFixed(1)} °C`
                        }
                        note={worstMargin < 0 ? undefined : 'to limit'}
                    />
                )}
            </div>

            <div className="topbar-end">
                <button
                    className={`alert-pill ${critical ? 'is-critical' : active.length ? 'is-warning' : ''}`}
                    onClick={() => setTab('alerts')}
                >
                    <span aria-hidden="true">
                        {critical ? '⬣' : active.length ? '▲' : '●'}
                    </span>
                    {active.length === 0
                        ? 'No alerts'
                        : `${active.length} active`}
                </button>
                <div className="source" title={useTwin.getState().statusDetail}>
                    <span
                        className={`source-dot is-${status}`}
                        aria-hidden="true"
                    />
                    <span>
                        {sourceLabel}
                        {measured.length > 0 &&
                            ` · ${measured.length} measured`}
                        {status !== 'online' && ` · ${status}`}
                    </span>
                </div>
                <div className={`clock ${replayT !== null ? 'is-replay' : ''}`}>
                    {view && def ? clock(view.t, def.utcOffsetHours) : '--:--'}
                    <small>{replayT !== null ? 'Replay' : 'Site time'}</small>
                </div>
            </div>
        </header>
    );
}

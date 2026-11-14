import { useMemo, useState } from 'react';
import { hasUploadedLayers } from '../core/layers.ts';
import {
    formatMetric,
    metricDefFor,
    primaryMetricFor,
} from '../core/metrics.ts';
import type { AssetDef } from '../core/types.ts';
import { State } from '../core/types.ts';
import { BODY_COLOR } from '../scene/colors.ts';
import {
    alertsAt,
    useTwin,
    type Basemap,
    type ColorBy,
    type Layers,
} from '../state/store.ts';
import { StateIcon } from './StatusBadge.tsx';

const COLOR_MODES: { id: ColorBy; label: string }[] = [
    { id: 'status', label: 'Status' },
    { id: 'temperature', label: 'Temp' },
    { id: 'power', label: 'Power' },
];

const LAYERS: { id: keyof Layers; label: string }[] = [
    { id: 'labels', label: 'Labels' },
    { id: 'flows', label: 'Energy flows' },
    { id: 'zones', label: 'Zones and routes' },
    { id: 'context', label: 'Uploaded layers' },
    { id: 'shadows', label: 'Sun shadows' },
];

export function LeftPanel() {
    const def = useTwin((s) => s.def);
    const view = useTwin((s) => s.view);
    const alerts = useTwin((s) => s.alerts);
    const selectedId = useTwin((s) => s.selectedId);
    const select = useTwin((s) => s.select);
    const colorBy = useTwin((s) => s.colorBy);
    const setColorBy = useTwin((s) => s.setColorBy);
    const layers = useTwin((s) => s.layers);
    const toggleLayer = useTwin((s) => s.toggleLayer);
    const basemap = useTwin((s) => s.basemap);
    const setBasemap = useTwin((s) => s.setBasemap);
    const [query, setQuery] = useState('');

    const groups = useMemo(() => {
        if (!def) return [];
        const q = query.trim().toLowerCase();
        return def.zones
            .map((zone) => ({
                zone,
                assets: def.assets.filter(
                    (a) =>
                        a.zone === zone.id &&
                        a.kind !== 'site' &&
                        (!q ||
                            a.name.toLowerCase().includes(q) ||
                            a.kind.includes(q)),
                ),
            }))
            .filter((g) => g.assets.length > 0);
    }, [def, query]);

    const alerted = new Set(alertsAt(alerts, view?.t).map((a) => a.assetId));

    return (
        <aside className="panel left-panel" aria-label="Assets and layers">
            <section className="tree">
                <input
                    className="search"
                    type="search"
                    placeholder="Find an asset"
                    aria-label="Find an asset"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                />
                <div className="tree-scroll">
                    {groups.length === 0 && def && (
                        <p className="muted pad">No assets match “{query}”.</p>
                    )}
                    {groups.map(({ zone, assets }) => (
                        <div key={zone.id}>
                            <h3 className="tree-zone">{zone.name}</h3>
                            {assets.map((a) => (
                                <AssetRow
                                    key={a.id}
                                    asset={a}
                                    state={view?.values[`${a.id}.state`]}
                                    value={
                                        view?.values[
                                            `${a.id}.${primaryMetricFor(def, a)}`
                                        ]
                                    }
                                    selected={a.id === selectedId}
                                    alerted={alerted.has(a.id)}
                                    onClick={() => select(a.id, true)}
                                />
                            ))}
                        </div>
                    ))}
                </div>
            </section>

            <section className="controls">
                <div className="control-group">
                    <h3>Colour by</h3>
                    <div
                        className="segmented"
                        role="radiogroup"
                        aria-label="Colour by"
                    >
                        {COLOR_MODES.map((m) => (
                            <button
                                key={m.id}
                                role="radio"
                                aria-checked={colorBy === m.id}
                                className={colorBy === m.id ? 'on' : ''}
                                onClick={() => setColorBy(m.id)}
                            >
                                {m.label}
                            </button>
                        ))}
                    </div>
                    <Legend mode={colorBy} />
                </div>
                <div className="control-group">
                    <h3>Layers</h3>
                    {LAYERS.filter(
                        (l) => l.id !== 'context' || hasUploadedLayers(def),
                    ).map((l) => (
                        <label key={l.id} className="check">
                            <input
                                type="checkbox"
                                checked={layers[l.id]}
                                onChange={() => toggleLayer(l.id)}
                            />
                            {l.label}
                        </label>
                    ))}
                </div>
                <div className="control-group">
                    <h3>Basemap</h3>
                    <select
                        aria-label="Basemap"
                        value={basemap}
                        onChange={(e) => setBasemap(e.target.value as Basemap)}
                    >
                        <option value="offline">Bundled, works offline</option>
                        <option value="osm">OpenStreetMap</option>
                        {import.meta.env.VITE_BASEMAP_URL && (
                            <option value="custom">Custom tiles</option>
                        )}
                    </select>
                </div>
            </section>
        </aside>
    );
}

function AssetRow({
    asset,
    state,
    value,
    selected,
    alerted,
    onClick,
}: {
    asset: AssetDef;
    state: number | undefined;
    value: number | undefined;
    selected: boolean;
    alerted: boolean;
    onClick: () => void;
}) {
    const def = useTwin((s) => s.def);
    const metric = metricDefFor(def, asset, primaryMetricFor(def, asset));
    return (
        <button
            className={`asset-row ${selected ? 'is-selected' : ''}`}
            onClick={onClick}
            aria-current={selected || undefined}
        >
            <StateIcon state={state} />
            <span className="asset-name">{asset.name}</span>
            {alerted && (
                <span
                    className="asset-flag"
                    title="Has an active alert"
                    aria-label="Has an active alert"
                >
                    ▲
                </span>
            )}
            <span className="asset-value">{formatMetric(metric, value)}</span>
        </button>
    );
}

function Legend({ mode }: { mode: ColorBy }) {
    if (mode === 'status') {
        const items = [
            State.Running,
            State.Standby,
            State.Maintenance,
            State.Off,
            State.Fault,
        ];
        const names = {
            [State.Running]: 'Running',
            [State.Standby]: 'Standby',
            [State.Maintenance]: 'Service',
            [State.Off]: 'Off',
            [State.Fault]: 'Fault',
        };
        return (
            <div className="legend">
                {items.map((s) => (
                    <span key={s} className="legend-item">
                        <span
                            className="legend-swatch"
                            style={{ background: BODY_COLOR[s] }}
                        />
                        {names[s]}
                    </span>
                ))}
            </div>
        );
    }
    return mode === 'temperature' ? (
        <div className="legend-ramp">
            <span className="ramp ramp-temp" />
            <div className="ramp-labels">
                <span>Colder</span>
                <span>Setpoint</span>
                <span>At limit</span>
            </div>
        </div>
    ) : (
        <div className="legend-ramp">
            <span className="ramp ramp-power" />
            <div className="ramp-labels">
                <span>Idle</span>
                <span>Full load</span>
            </div>
        </div>
    );
}

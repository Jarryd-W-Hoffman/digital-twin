import { useEffect, useRef, useState } from 'react';
import type { Forecast, ForecastRun } from '../core/forecast.ts';
import type { TwinDefinition } from '../core/types.ts';
import { useTwin } from '../state/store.ts';
import { clock, duration } from './format.ts';
import { LineChart } from './LineChart.tsx';
import { SeverityBadge } from './StatusBadge.tsx';

const HORIZONS = [2, 6, 12, 24];
// Validated dark-mode categorical slots 1 and 2.
const BASELINE = '#3987e5';
const SCENARIO = '#d95926';

export function WhatIfPanel() {
    const def = useTwin((s) => s.def);
    const simState = useTwin((s) => s.simState);
    const forecast = useTwin((s) => s.forecast);
    const predict = useTwin((s) => s.predict);
    const send = useTwin((s) => s.send);
    const replayT = useTwin((s) => s.replayT);
    const goLive = useTwin((s) => s.goLive);
    const [scenarioId, setScenarioId] = useState<string | null>(null);
    const [hours, setHours] = useState(12);
    if (!def) return null;
    const selected = scenarioId ?? def.scenarios[0]?.id ?? '';
    const scenario = def.scenarios.find((s) => s.id === selected);
    const now = simState?.t ?? 0;
    const activeFaults = (simState?.faults ?? []).filter(
        (f) => f.endT === undefined || f.endT > now,
    );

    return (
        <div className="inspector">
            <div className="inspector-head">
                <h2>What-if</h2>
                <p className="muted">
                    Runs the model ahead from this moment, with and without the
                    event. Nothing changes on site until you apply it.
                </p>
            </div>

            {activeFaults.length > 0 && (
                <div className="callout is-warning">
                    <span>
                        {activeFaults.length} active{' '}
                        {activeFaults.length === 1 ? 'event' : 'events'} in the
                        model
                    </span>
                    <button
                        className="link"
                        onClick={() => send({ type: 'clear-faults' })}
                    >
                        Clear
                    </button>
                </div>
            )}

            <fieldset className="scenario-list">
                <legend>Event</legend>
                {def.scenarios.map((s) => (
                    <label
                        key={s.id}
                        className={`scenario ${s.id === selected ? 'is-on' : ''}`}
                    >
                        <input
                            type="radio"
                            name="scenario"
                            checked={s.id === selected}
                            onChange={() => {
                                setScenarioId(s.id);
                                useTwin.getState().clearForecast();
                            }}
                        />
                        <span>
                            <strong>{s.name}</strong>
                            <small>{s.description}</small>
                        </span>
                    </label>
                ))}
            </fieldset>

            {replayT !== null && (
                <p className="muted hint">
                    Forecasts start from the live state, not the moment you are
                    replaying.
                </p>
            )}

            <div className="control-row">
                <label htmlFor="horizon">Look ahead</label>
                <select
                    id="horizon"
                    value={hours}
                    onChange={(e) => setHours(Number(e.target.value))}
                >
                    {HORIZONS.map((h) => (
                        <option key={h} value={h}>
                            {h} hours
                        </option>
                    ))}
                </select>
                <button
                    className="primary"
                    disabled={!scenario || !simState}
                    onClick={() => predict(selected, hours * 3600)}
                >
                    Run forecast
                </button>
            </div>

            {forecast && forecast.scenarioId === selected && (
                <Results def={def} forecast={forecast.result} />
            )}

            {forecast && forecast.scenarioId === selected && (
                <button
                    className="danger wide"
                    onClick={() => {
                        if (replayT !== null) goLive();
                        send({ type: 'scenario', scenario: selected });
                    }}
                >
                    Apply to the live twin
                </button>
            )}
        </div>
    );
}

function Results({
    def,
    forecast,
}: {
    def: TwinDefinition;
    forecast: Forecast;
}) {
    const { baseline, scenario, startT, storeIds } = forecast;
    const assetName = (id: string) =>
        def.assets.find((a) => a.id === id)?.name ?? id;
    // Lead with the consequence: a tripped breaker matters because of what it spoils.
    const isStore = (id: string) =>
        def.assets.find((a) => a.id === id)?.kind === 'coldstore';
    const criticals = scenario.alerts.filter((a) => a.severity === 'critical');
    const breach =
        criticals.find((a) => isStore(a.assetId)) ?? scenario.firstCritical;
    const root = useRef<HTMLDivElement>(null);
    useEffect(() => {
        root.current?.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }, [forecast]);
    const predicted = [...scenario.alerts].sort(
        (a, b) => a.raisedAt - b.raisedAt,
    );
    return (
        <div className="results" data-testid="forecast-results" ref={root}>
            <p className={`verdict ${breach ? 'is-critical' : 'is-ok'}`}>
                {breach ? (
                    <>
                        <strong>{breach.message}</strong> in{' '}
                        {duration(breach.raisedAt - startT)}.
                    </>
                ) : (
                    <>
                        <strong>No critical alerts</strong> within{' '}
                        {duration(forecast.horizonS * 1000)}.
                    </>
                )}
            </p>

            {storeIds.map((id) => {
                const store = def.assets.find((a) => a.id === id)!;
                const toPoints = (run: ForecastRun) =>
                    run.t.map((t, i) => ({
                        t,
                        v: run.series[`${id}.tempC`]![i]!,
                    }));
                return (
                    <div key={id} className="result-chart">
                        <h3>{assetName(id)}</h3>
                        <LineChart
                            series={[
                                {
                                    key: 'baseline',
                                    label: 'No event',
                                    color: BASELINE,
                                    points: toPoints(baseline),
                                },
                                {
                                    key: 'scenario',
                                    label: 'With event',
                                    color: SCENARIO,
                                    points: toPoints(scenario),
                                },
                            ]}
                            references={[
                                {
                                    value: store.params?.limitC ?? 0,
                                    label: `Limit ${store.params?.limitC} °C`,
                                },
                            ]}
                            height={128}
                            formatValue={(v) => `${v.toFixed(1)} °C`}
                            formatTime={(t) => clock(t, def.utcOffsetHours)}
                            label={`${assetName(id)} temperature forecast`}
                        />
                    </div>
                );
            })}
            <div className="legend">
                <span className="legend-item">
                    <span
                        className="chart-key"
                        style={{ background: BASELINE }}
                    />
                    No event
                </span>
                <span className="legend-item">
                    <span
                        className="chart-key"
                        style={{ background: SCENARIO }}
                    />
                    With event
                </span>
            </div>

            {predicted.length > 0 && (
                <div className="result-chart">
                    <h3>What would happen</h3>
                    <ol className="predicted">
                        {predicted.map((a) => (
                            <li key={a.id}>
                                <span className="mono muted">
                                    +{duration(a.raisedAt - startT)}
                                </span>
                                <SeverityBadge severity={a.severity} />
                                <span>{a.message}</span>
                            </li>
                        ))}
                    </ol>
                </div>
            )}

            <table className="compare">
                <thead>
                    <tr>
                        <th scope="col">
                            Over {duration(forecast.horizonS * 1000)}
                        </th>
                        <th scope="col">No event</th>
                        <th scope="col">With event</th>
                    </tr>
                </thead>
                <tbody>
                    <Row
                        label="Run cost"
                        a={baseline.cost}
                        b={scenario.cost}
                        format={(v) =>
                            `$${Math.round(v).toLocaleString('en-AU')}`
                        }
                    />
                    <Row
                        label="CO₂"
                        a={baseline.co2Kg}
                        b={scenario.co2Kg}
                        format={(v) =>
                            `${Math.round(v).toLocaleString('en-AU')} kg`
                        }
                    />
                    <Row
                        label="Diesel"
                        a={baseline.dieselLitres}
                        b={scenario.dieselLitres}
                        format={(v) =>
                            `${Math.round(v).toLocaleString('en-AU')} L`
                        }
                    />
                    <Row
                        label="Load not served"
                        a={baseline.unservedKwh}
                        b={scenario.unservedKwh}
                        format={(v) =>
                            `${Math.round(v).toLocaleString('en-AU')} kWh`
                        }
                    />
                    <Row
                        label="Stock at risk"
                        a={baseline.stockAtRiskT}
                        b={scenario.stockAtRiskT}
                        format={(v) =>
                            `${Math.round(v).toLocaleString('en-AU')} t`
                        }
                    />
                    <Row
                        label="Critical alerts"
                        a={
                            baseline.alerts.filter(
                                (x) => x.severity === 'critical',
                            ).length
                        }
                        b={
                            scenario.alerts.filter(
                                (x) => x.severity === 'critical',
                            ).length
                        }
                        format={String}
                    />
                </tbody>
            </table>
        </div>
    );
}

function Row({
    label,
    a,
    b,
    format,
}: {
    label: string;
    a: number;
    b: number;
    format: (v: number) => string;
}) {
    return (
        <tr>
            <th scope="row">{label}</th>
            <td>{format(a)}</td>
            <td className={format(a) !== format(b) ? 'changed' : ''}>
                {format(b)}
            </td>
        </tr>
    );
}

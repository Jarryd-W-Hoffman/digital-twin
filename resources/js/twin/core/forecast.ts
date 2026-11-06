import { advance, applyCommand, cloneState, type Model } from './engine.ts';
import { createRuleEngine } from './rules.ts';
import type { Alert, Command, SimState } from './types.ts';

/**
 * What-if analysis: fork the current state, run the model ahead with and
 * without an intervention, and compare the two futures. Both forks share the
 * same seed and noise, so every difference comes from the intervention.
 */

export const FORECAST_SAMPLE_S = 300;

export interface ForecastRun {
    t: number[];
    series: Record<string, number[]>;
    alerts: Alert[];
    firstCritical?: Alert;
    cost: number;
    co2Kg: number;
    unservedKwh: number;
    dieselLitres: number;
    stockAtRiskT: number;
    peakTempC: Record<string, number>;
}

export interface Forecast {
    horizonS: number;
    startT: number;
    baseline: ForecastRun;
    scenario: ForecastRun;
    storeIds: string[];
}

export function runForecast(
    model: Model,
    state: SimState,
    commands: Command[],
    horizonS: number,
): Forecast {
    const storeIds = model.byKind('coldstore').map((s) => s.id);
    return {
        horizonS,
        startT: state.t,
        baseline: runFork(model, state, [], horizonS, storeIds),
        scenario: runFork(model, state, commands, horizonS, storeIds),
        storeIds,
    };
}

function runFork(
    model: Model,
    from: SimState,
    commands: Command[],
    horizonS: number,
    storeIds: string[],
): ForecastRun {
    const state = cloneState(from);
    for (const c of commands) applyCommand(model, state, c);
    const site = model.byKind('site')[0]?.id;
    const genset = model.byKind('genset')[0]?.id;
    const rules = createRuleEngine(model.def);
    const read = (key: string) => state.values[key] ?? 0;

    const tracked = storeIds.map((id) => `${id}.tempC`);
    const run: ForecastRun = {
        t: [],
        series: Object.fromEntries(tracked.map((k) => [k, []])),
        alerts: [],
        cost: 0,
        co2Kg: 0,
        unservedKwh: 0,
        dieselLitres: 0,
        stockAtRiskT: 0,
        peakTempC: Object.fromEntries(
            storeIds.map((id) => [id, read(`${id}.tempC`)]),
        ),
    };
    const costStart = read(`${site}.costTotal`);
    const co2Start = read(`${site}.co2Total`);
    const unservedStart = read(`${site}.unservedKwh`);
    const dieselStart = read(`${genset}.litres`);

    const sample = () => {
        run.t.push(state.t);
        for (const k of tracked) run.series[k]!.push(read(k));
    };
    sample();
    const samples = Math.round(horizonS / FORECAST_SAMPLE_S);
    for (let i = 0; i < samples; i++) {
        // Rule hold times are minutes, so evaluate each simulated minute.
        for (let minute = 0; minute < FORECAST_SAMPLE_S / 60; minute++) {
            advance(model, state, 60);
            rules.evaluate({ t: state.t, values: state.values });
            for (const id of storeIds) {
                run.peakTempC[id] = Math.max(
                    run.peakTempC[id]!,
                    read(`${id}.tempC`),
                );
            }
        }
        sample();
    }
    run.alerts = rules.alerts();
    run.firstCritical = run.alerts.find((a) => a.severity === 'critical');
    run.cost = read(`${site}.costTotal`) - costStart;
    run.co2Kg = read(`${site}.co2Total`) - co2Start;
    run.unservedKwh = read(`${site}.unservedKwh`) - unservedStart;
    run.dieselLitres = read(`${genset}.litres`) - dieselStart;
    for (const id of storeIds) run.stockAtRiskT += read(`${id}.atRiskT`);
    return run;
}

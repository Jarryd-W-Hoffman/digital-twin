import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { MODE, POWERED, stateLabelOf } from './behaviour.ts';
import { advance, applyCommand, compile, initialState } from './engine.ts';
import { createRuleEngine } from './rules.ts';
import type { AssetTypeDef, TwinDefinition } from './types.ts';
import { State } from './types.ts';
import { validateDefinition } from './validate.ts';

const NOON = Date.UTC(2026, 10, 5, 0, 0, 0); // 12:00 at UTC+12
const MINUTE = 60;

const tank: AssetTypeDef = {
    id: 'tank',
    name: 'Header tank',
    primary: 'levelPct',
    metrics: [
        {
            key: 'levelPct',
            label: 'Level',
            unit: '%',
            default: 50,
            expr: 'clamp(levelPct + (pumping ? 1 : -0.5) * dt / 60, 0, 100)',
        },
        { key: 'powerKw', unit: 'kW', expr: 'pumping ? 4 : 0' },
        {
            key: 'targetPct',
            unit: '%',
            default: 60,
            setpoint: { min: 10, max: 95, step: 5 },
        },
        {
            key: 'broken',
            unit: '',
            default: 7,
            expr: '1 / (levelPct - levelPct)',
        },
        { key: 'probeC', unit: '°C', default: 4 },
    ],
    states: [
        { id: 'pumping', label: 'Pumping', status: 'running' },
        { id: 'resting', status: 'standby' },
    ],
    transitions: [
        { from: 'pumping', to: 'resting', when: 'levelPct >= targetPct' },
        {
            from: '*',
            to: 'pumping',
            when: 'levelPct < targetPct - 10 && powered',
        },
    ],
    visual: { color: '#3a7ca5', shape: 'cylinder' },
};

/** Harbour Depot with a pumped header tank on its main board. */
function depot(withTank = true): TwinDefinition {
    const def = JSON.parse(
        readFileSync('public/samples/harbour-depot.json', 'utf8'),
    ) as TwinDefinition;
    def.scenarios.push({
        id: 'board-trip',
        name: 'Board trips',
        description: '',
        faults: [{ type: 'trip', target: 'board' }],
    });
    if (!withTank) return def;
    def.types = [tank];
    def.assets.push({
        id: 'tank-1',
        name: 'Header tank',
        kind: 'custom',
        type: 'tank',
        zone: 'yard',
        position: [10, 10],
        fedBy: 'board',
    });
    return def;
}

describe('custom type behaviour', () => {
    it('is a valid definition', () => {
        expect(validateDefinition(depot())).toEqual([]);
    });

    it('works out derived metrics and moves between states', () => {
        const model = compile(depot());
        const state = initialState(model, NOON);
        const v = state.values;
        expect(v['tank-1.levelPct']).toBeCloseTo(50 + 5 / 60, 5);
        expect(v['tank-1.state']).toBe(State.Running);
        expect(stateLabelOf(model.def, model.byId.get('tank-1')!, v)).toBe(
            'Pumping',
        );

        // Ten minutes of pumping at 1% a minute reaches the 60% target.
        advance(model, state, 10 * MINUTE);
        expect(v[`tank-1.${MODE}`]).toBe(1);
        expect(v['tank-1.state']).toBe(State.Standby);
        expect(v['tank-1.powerKw']).toBe(0);
        expect(stateLabelOf(model.def, model.byId.get('tank-1')!, v)).toBe(
            'resting',
        );

        // Draining at 0.5% a minute, it starts pumping again below 50%.
        advance(model, state, 21 * MINUTE);
        expect(v['tank-1.state']).toBe(State.Running);
        expect(v['tank-1.levelPct']).toBeLessThan(51);
    });

    it('keeps a value whose expression has none, and holds plain metrics', () => {
        const model = compile(depot());
        const state = advance(model, initialState(model, NOON), 5 * MINUTE);
        expect(state.values['tank-1.broken']).toBe(7);
        expect(state.values['tank-1.probeC']).toBe(4);
    });

    it('draws its power from its bus', () => {
        const withTank = compile(depot());
        const without = compile(depot(false));
        const a = initialState(withTank, NOON).values;
        const b = initialState(without, NOON).values;
        expect(a['board.loadKw']! - b['board.loadKw']!).toBeCloseTo(4, 6);
        expect(a['site.loadKw']! - b['site.loadKw']!).toBeCloseTo(4, 6);
    });

    it('goes off with its bus, and stays put until it is back', () => {
        const model = compile(depot());
        const state = initialState(model, NOON);
        applyCommand(model, state, {
            type: 'scenario',
            scenario: 'board-trip',
        });
        advance(model, state, MINUTE);
        const v = state.values;
        expect(v[`tank-1.${POWERED}`]).toBe(0);
        expect(v['tank-1.state']).toBe(State.Off);
        expect(v['tank-1.powerKw']).toBe(0);

        // Resting, so it drains; off its bus it can't start pumping again.
        state.values[`tank-1.${MODE}`] = 1;
        advance(model, state, 30 * MINUTE);
        expect(v[`tank-1.${MODE}`]).toBe(1);
        expect(v['tank-1.levelPct']).toBeLessThan(50);

        applyCommand(model, state, { type: 'clear-faults' });
        advance(model, state, 2 * MINUTE);
        expect(v[`tank-1.${MODE}`]).toBe(0);
        expect(v['tank-1.state']).toBe(State.Running);
    });

    it('takes setpoints for declared metrics, kept within range', () => {
        const model = compile(depot());
        const state = initialState(model, NOON);
        const set = (metric: string | undefined, value: number) =>
            applyCommand(model, state, {
                type: 'setpoint',
                asset: 'tank-1',
                metric,
                value,
            });
        set('targetPct', 80);
        expect(state.values['tank-1.targetPct']).toBe(80);
        set('targetPct', 400);
        expect(state.values['tank-1.targetPct']).toBe(95);
        expect(() => set('levelPct', 10)).toThrow(
            'tank-1 has no setpoint levelPct',
        );
        expect(() => set(undefined, 10)).toThrow('tank-1 has no setpoint ');
        expect(() => set('targetPct', Number.NaN)).toThrow();
        expect(() =>
            applyCommand(model, state, {
                type: 'setpoint',
                asset: 'board',
                value: 1,
            }),
        ).toThrow('board is not a cold store');
    });

    it('runs a type with no behaviour, or an asset with no type, as before', () => {
        const def = depot();
        def.types = [{ ...tank, states: undefined, transitions: [] }];
        def.assets.push({
            id: 'orphan',
            name: 'Orphan',
            kind: 'custom',
            type: 'missing',
            zone: 'yard',
            position: [0, 0],
        });
        const model = compile(def);
        const v = advance(model, initialState(model, NOON), MINUTE).values;
        expect(v['tank-1.state']).toBe(State.Running);
        expect(v['orphan.state']).toBe(State.Running);
        expect(stateLabelOf(def, model.byId.get('tank-1')!, v)).toBeUndefined();
        expect(stateLabelOf(def, model.byId.get('store')!, v)).toBeUndefined();
        expect(
            stateLabelOf(undefined, model.byId.get('orphan')!, v),
        ).toBeUndefined();
    });
});

describe('expression and change rules', () => {
    const def = (rules: TwinDefinition['rules']): TwinDefinition => ({
        ...depot(),
        rules,
    });

    it('raises while an expression holds, and clears when it stops', () => {
        const e = createRuleEngine(
            def([
                {
                    id: 'stuck',
                    name: 'Stuck',
                    severity: 'warning',
                    asset: 'tank-1',
                    when: 'resting && levelPct < 20 && {board.loadKw} > 0',
                    message: '{asset} is low but resting',
                },
            ]),
        );
        const frame = (t: number, levelPct: number, mode = 1) => ({
            t,
            values: {
                'tank-1.levelPct': levelPct,
                [`tank-1.${MODE}`]: mode,
                'board.loadKw': 30,
            },
        });
        expect(e.evaluate(frame(0, 30)).raised).toEqual([]);
        const [alert] = e.evaluate(frame(1000, 15)).raised;
        expect(alert).toMatchObject({
            assetId: 'tank-1',
            value: 1,
            message: 'Header tank is low but resting',
        });
        expect(e.evaluate(frame(2000, 15, 0)).cleared).toEqual([alert]);
        // Nothing to read: the rule waits rather than guessing.
        expect(e.evaluate({ t: 3000, values: {} })).toEqual({
            raised: [],
            cleared: [],
        });
    });

    it('clears only once clearWhen holds, and shows its metric', () => {
        const e = createRuleEngine(
            def([
                {
                    id: 'warm',
                    name: 'Warm',
                    severity: 'critical',
                    kind: 'coldstore',
                    metric: 'tempC',
                    when: 'tempC > setpointC + 3',
                    clearWhen: 'tempC < setpointC + 1',
                    message: '{asset} warm',
                },
            ]),
        );
        const frame = (t: number, tempC: number) => ({
            t,
            values: { 'store.tempC': tempC, 'store.setpointC': 1 },
        });
        expect(e.evaluate(frame(0, 5)).raised[0]?.value).toBe(5);
        expect(e.evaluate(frame(1, 3)).cleared).toEqual([]);
        expect(e.evaluate(frame(2, 0.5)).cleared).toHaveLength(1);
    });

    it('compares how much a metric changed over a window', () => {
        const e = createRuleEngine(
            def([
                {
                    id: 'rising',
                    name: 'Rising',
                    severity: 'warning',
                    asset: 'store',
                    metric: 'tempC',
                    op: 'gt',
                    threshold: 1,
                    change: { overS: 600 },
                    message: '{asset} warming fast',
                },
            ]),
        );
        const at = (minute: number, tempC: number) =>
            e.evaluate({
                t: minute * 60_000,
                values: { 'store.tempC': tempC },
            });
        // Not ten minutes of readings yet, so nothing to compare.
        expect(at(0, 1).raised).toEqual([]);
        expect(at(5, 3).raised).toEqual([]);
        // 0.5 °C over the ten minutes since minute 0.
        expect(at(10, 1.5).raised).toEqual([]);
        // 2 °C over the ten minutes since minute 5 (3 °C → 5 °C).
        const [alert] = at(15, 5).raised;
        expect(alert?.value).toBeCloseTo(2, 6);
        expect(at(25, 5).cleared).toHaveLength(1);
        // Time going backwards starts the window again.
        expect(at(1, 9).raised).toEqual([]);
    });
});

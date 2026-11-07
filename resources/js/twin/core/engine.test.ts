import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    advance,
    applyCommand,
    cloneState,
    compile,
    initialState,
} from './engine.ts';
import { runForecast } from './forecast.ts';
import { State, type SimState } from './types.ts';
import { parseDefinition } from './validate.ts';

const def = parseDefinition(
    JSON.parse(readFileSync('public/samples/northshore.json', 'utf8')),
);
const model = compile(def);
const NOON = Date.UTC(2026, 10, 5, 2, 0, 0); // 12:00 at UTC+10
const MIDNIGHT = Date.UTC(2026, 10, 4, 14, 0, 0);
const HOUR = 3600;

const settled = (at = NOON): SimState =>
    advance(model, initialState(model, at - 4 * HOUR * 1000), 4 * HOUR);

describe('energy balance', () => {
    it('conserves power, including while islanded', () => {
        const state = settled();
        const v = state.values;
        const check = () => {
            const supply =
                v['site.gridKw']! +
                v['site.solarKw']! +
                v['site.batteryKw']! +
                v['site.gensetKw']!;
            expect(supply).toBeCloseTo(v['site.loadKw']!, 3);
        };
        check();
        advance(model, state, HOUR * 8);
        check();
        applyCommand(model, state, { type: 'scenario', scenario: 'blackout' });
        for (let i = 0; i < 60; i++) {
            advance(model, state, 600);
            check();
        }
    });

    it('makes solar at noon and none at midnight', () => {
        expect(settled(NOON).values['site.solarKw']).toBeGreaterThan(100);
        expect(settled(MIDNIGHT).values['site.solarKw']).toBe(0);
    });

    it('keeps the battery within 0..100%', () => {
        const state = settled();
        applyCommand(model, state, { type: 'scenario', scenario: 'blackout' });
        for (let i = 0; i < 100; i++) {
            advance(model, state, 300);
            expect(state.values['bess.socPct']).toBeGreaterThanOrEqual(0);
            expect(state.values['bess.socPct']).toBeLessThanOrEqual(100);
        }
    });

    it('runs a full day without a single alert-worthy fault when nothing is wrong', () => {
        const state = settled();
        for (let i = 0; i < 288; i++) {
            advance(model, state, 300);
            expect(state.values['site.unservedKw']).toBeLessThan(1);
            expect(state.values['site.islanded']).toBe(0);
        }
    });
});

describe('cold stores', () => {
    it('holds setpoint in normal running', () => {
        const state = settled();
        for (let i = 0; i < 288; i++) {
            advance(model, state, 300);
            expect(Math.abs(state.values['freezer.tempC']! + 20)).toBeLessThan(
                1.5,
            );
            expect(Math.abs(state.values['chillroom.tempC']! - 3)).toBeLessThan(
                1.5,
            );
        }
    });

    it('follows a setpoint command', () => {
        const state = settled();
        applyCommand(model, state, {
            type: 'setpoint',
            asset: 'chillroom',
            value: 1,
        });
        advance(model, state, HOUR * 8);
        expect(Math.abs(state.values['chillroom.tempC']! - 1)).toBeLessThan(
            1.5,
        );
    });

    it('warms when its board trips, and accrues stock risk over time', () => {
        const state = settled();
        const before = state.values['freezer.tempC']!;
        applyCommand(model, state, {
            type: 'scenario',
            scenario: 'board-trip',
        });
        advance(model, state, HOUR);
        expect(state.values['ch-1.coolingKw']).toBe(0);
        expect(state.values['ch-3.state']).toBe(State.Off);
        expect(state.values['db-cold.state']).toBe(State.Fault);
        expect(state.values['freezer.tempC']).toBeGreaterThan(before + 0.5);
        advance(model, state, HOUR * 12);
        expect(state.values['freezer.atRiskT']).toBeGreaterThan(0);
    });

    it('takes a chiller out of service for maintenance', () => {
        const state = settled();
        applyCommand(model, state, {
            type: 'maintenance',
            asset: 'ch-3',
            on: true,
        });
        advance(model, state, 60);
        expect(state.values['ch-3.state']).toBe(State.Maintenance);
        expect(state.values['ch-3.coolingKw']).toBe(0);
        applyCommand(model, state, {
            type: 'maintenance',
            asset: 'ch-3',
            on: false,
        });
        advance(model, state, 600);
        expect(state.values['ch-3.state']).toBe(State.Running);
    });

    it('rejects commands aimed at the wrong kind of asset', () => {
        const state = settled();
        expect(() =>
            applyCommand(model, state, {
                type: 'setpoint',
                asset: 'ch-1',
                value: 1,
            }),
        ).toThrow();
        expect(() =>
            applyCommand(model, state, {
                type: 'maintenance',
                asset: 'freezer',
                on: true,
            }),
        ).toThrow();
        expect(() =>
            applyCommand(model, state, { type: 'scenario', scenario: 'nope' }),
        ).toThrow();
    });
});

describe('outage response', () => {
    it('bridges on battery, starts the generator, then returns to grid', () => {
        const state = settled();
        applyCommand(model, state, {
            type: 'scenario',
            scenario: 'grid-outage',
        });
        advance(model, state, 30);
        expect(state.values['site.islanded']).toBe(1);
        expect(state.values['gen.powerKw']).toBe(0);
        expect(state.values['site.unservedKw']).toBeLessThan(1);
        advance(model, state, 120);
        expect(state.values['gen.state']).toBe(State.Running);
        advance(model, state, HOUR * 4);
        expect(state.values['site.islanded']).toBe(0);
        expect(state.values['gen.powerKw']).toBe(0);
    });

    it('sheds non-critical load first, keeping refrigeration on', () => {
        // After dark, with a battery too small for the whole site, priorities must decide.
        const small = structuredClone(def);
        small.assets.find((a) => a.id === 'bess')!.params!.maxKw = 120;
        const m = compile(small);
        const evening = NOON + 8 * HOUR * 1000;
        const state = advance(
            m,
            initialState(m, evening - 4 * HOUR * 1000),
            4 * HOUR,
        );
        applyCommand(m, state, { type: 'scenario', scenario: 'blackout' });
        advance(m, state, 60);
        expect(state.values['site.solarKw']).toBe(0);
        expect(state.values['admin-office.state']).toBe(State.Off);
        expect(state.values['db-cold.state']).toBe(State.Running);
        expect(state.values['ch-1.coolingKw']).toBeGreaterThan(0);
        expect(state.values['site.unservedKw']).toBeGreaterThan(0);
    });

    it('eventually blacks out when the battery is exhausted overnight', () => {
        const state = settled(MIDNIGHT);
        applyCommand(model, state, { type: 'scenario', scenario: 'blackout' });
        advance(model, state, HOUR * 5);
        expect(state.values['db-cold.state']).toBe(State.Off);
        expect(state.values['ch-1.coolingKw']).toBe(0);
        // Sunrise restores refrigeration even before the grid returns.
        advance(model, state, HOUR * 2.5);
        expect(state.values['site.islanded']).toBe(1);
        expect(state.values['db-cold.state']).toBe(State.Running);
    });

    it('does not flicker the site once the battery is exhausted', () => {
        const state = settled();
        applyCommand(model, state, { type: 'scenario', scenario: 'blackout' });
        let transitions = 0;
        let wasOn = true;
        for (let i = 0; i < 480; i++) {
            advance(model, state, 60);
            const on = state.values['db-cold.state'] === State.Running;
            if (on !== wasOn) transitions += 1;
            wasOn = on;
        }
        expect(transitions).toBeLessThan(12);
    });
});

describe('determinism and forking', () => {
    it('reproduces a run exactly from a clone', () => {
        const a = settled();
        const b = cloneState(a);
        advance(model, a, HOUR);
        advance(model, b, HOUR);
        expect(b.values).toEqual(a.values);
    });

    it('forecast leaves live state untouched and its baseline matches a straight run', () => {
        const state = settled();
        const snapshot = JSON.stringify(state);
        const f = runForecast(
            model,
            state,
            [{ type: 'scenario', scenario: 'board-trip' }],
            HOUR * 2,
        );
        expect(JSON.stringify(state)).toBe(snapshot);
        const straight = advance(model, cloneState(state), HOUR * 2);
        expect(f.baseline.series['freezer.tempC']!.at(-1)).toBeCloseTo(
            straight.values['freezer.tempC']!,
            9,
        );
    });

    it('forecast shows a fault is worse than doing nothing', () => {
        const state = settled();
        const f = runForecast(
            model,
            state,
            [{ type: 'scenario', scenario: 'board-trip' }],
            HOUR * 6,
        );
        expect(f.scenario.peakTempC.freezer).toBeGreaterThan(
            f.baseline.peakTempC.freezer! + 1,
        );
        expect(f.scenario.firstCritical).toBeDefined();
        expect(f.baseline.firstCritical).toBeUndefined();
    });

    it('survives a JSON round trip, as it would crossing a socket', () => {
        const state = settled();
        const wire = JSON.parse(JSON.stringify(state)) as SimState;
        advance(model, state, HOUR);
        advance(model, wire, HOUR);
        expect(wire.values).toEqual(state.values);
    });
});

describe('custom types with behaviour', () => {
    it('cycles the jockey pump to hold the ring main between its setpoints', () => {
        const state = settled();
        const v = state.values;
        let starts = 0;
        let wasRunning = v['jockey.powerKw']! > 0;
        let low = Infinity;
        let high = -Infinity;
        for (let minute = 0; minute < 6 * 60; minute++) {
            advance(model, state, 60);
            const running = v['jockey.powerKw']! > 0;
            if (running && !wasRunning) starts++;
            wasRunning = running;
            low = Math.min(low, v['fire-tank.pressureKpa']!);
            high = Math.max(high, v['fire-tank.pressureKpa']!);
            expect(v['jockey.runMin']!).toBeLessThan(20);
        }
        expect(starts).toBeGreaterThanOrEqual(4);
        expect(low).toBeGreaterThan(645);
        expect(high).toBeLessThan(745);
    });

    it("lets an operator move the pump's start pressure", () => {
        const state = settled();
        applyCommand(model, state, {
            type: 'setpoint',
            asset: 'jockey',
            metric: 'startKpa',
            value: 700,
        });
        advance(model, state, 3 * HOUR);
        expect(state.values['fire-tank.pressureKpa']!).toBeGreaterThan(695);
    });
});

describe('vehicles', () => {
    it('moves along its route and keeps charge across two days', () => {
        const state = settled();
        const start = [state.values['truck-1.x'], state.values['truck-1.y']];
        let moved = false;
        for (let i = 0; i < 576; i++) {
            advance(model, state, 300);
            if (
                state.values['truck-1.x'] !== start[0] ||
                state.values['truck-1.y'] !== start[1]
            )
                moved = true;
            for (const id of [
                'truck-1',
                'truck-2',
                'truck-3',
                'agv-1',
                'agv-2',
            ]) {
                expect(state.values[`${id}.batteryPct`]).toBeGreaterThan(10);
            }
        }
        expect(moved).toBe(true);
    });
});

describe('bearing wear', () => {
    it('shows in vibration before efficiency has dropped much', () => {
        const state = settled();
        const cop = state.values['ch-2.cop']!;
        applyCommand(model, state, { type: 'scenario', scenario: 'bearing' });
        advance(model, state, HOUR * 3);
        expect(state.values['ch-2.vibrationMms']).toBeGreaterThan(2.8);
        expect(state.values['ch-2.cop']! / cop).toBeGreaterThan(0.85);
    });
});

describe('other twins', () => {
    it('runs a minimal twin with no battery, solar, generator or vehicles', () => {
        const small = parseDefinition(
            JSON.parse(
                readFileSync('public/samples/harbour-depot.json', 'utf8'),
            ),
        );
        const m = compile(small);
        const state = advance(m, initialState(m, NOON), HOUR * 24);
        const store = small.assets.find((a) => a.kind === 'coldstore')!;
        expect(Number.isFinite(state.values[`${store.id}.tempC`])).toBe(true);
        expect(
            Math.abs(
                state.values[`${store.id}.tempC`]! - store.params!.setpointC!,
            ),
        ).toBeLessThan(2);
        const forecast = runForecast(
            m,
            state,
            [{ type: 'scenario', scenario: small.scenarios[0]!.id }],
            HOUR,
        );
        expect(forecast.scenario.firstCritical).toBeDefined();
    });
});

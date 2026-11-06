import { describe, expect, it } from 'vitest';
import { centroid, metresToLonLat, pointAlong } from './geo.ts';
import { History } from './history.ts';
import { formatMetric, METRICS } from './metrics.ts';
import { activeAt, createRuleEngine } from './rules.ts';
import type { Alert, Point, TwinDefinition } from './types.ts';
import { validateDefinition } from './validate.ts';

const baseDef = (rules: TwinDefinition['rules']): TwinDefinition =>
    ({
        id: 't',
        name: 'T',
        description: '',
        origin: { lon: 0, lat: 0 },
        utcOffsetHours: 0,
        climate: { meanC: 20, swingC: 5 },
        camera: { headingDeg: 0, pitchDeg: -30, rangeM: 100 },
        tariff: {} as TwinDefinition['tariff'],
        zones: [],
        assets: [
            {
                id: 'main',
                name: 'Main',
                kind: 'bus',
                zone: '',
                position: [0, 0],
            },
            {
                id: 'room',
                name: 'Room',
                kind: 'coldstore',
                zone: '',
                position: [1, 1],
            },
        ],
        routes: [],
        rules,
        scenarios: [],
    }) as TwinDefinition;

describe('rules', () => {
    const rule = {
        id: 'hot',
        name: 'Hot',
        severity: 'critical' as const,
        kind: 'coldstore' as const,
        metric: 'tempC',
        op: 'gt' as const,
        threshold: 5,
        clearAt: 3,
        forS: 60,
        message: '{asset} hot',
    };

    it('waits out the hold time, then raises once', () => {
        const e = createRuleEngine(baseDef([rule]));
        expect(
            e.evaluate({ t: 0, values: { 'room.tempC': 6 } }).raised,
        ).toHaveLength(0);
        expect(
            e.evaluate({ t: 30_000, values: { 'room.tempC': 6 } }).raised,
        ).toHaveLength(0);
        const { raised } = e.evaluate({
            t: 60_000,
            values: { 'room.tempC': 6 },
        });
        expect(raised).toHaveLength(1);
        expect(raised[0]!.message).toBe('Room hot');
        expect(
            e.evaluate({ t: 90_000, values: { 'room.tempC': 6 } }).raised,
        ).toHaveLength(0);
    });

    it('resets the hold if the breach ends early', () => {
        const e = createRuleEngine(baseDef([rule]));
        e.evaluate({ t: 0, values: { 'room.tempC': 6 } });
        e.evaluate({ t: 30_000, values: { 'room.tempC': 4 } });
        expect(
            e.evaluate({ t: 60_000, values: { 'room.tempC': 6 } }).raised,
        ).toHaveLength(0);
    });

    it('holds the alert open within the hysteresis band', () => {
        const e = createRuleEngine(baseDef([{ ...rule, forS: 0 }]));
        e.evaluate({ t: 0, values: { 'room.tempC': 6 } });
        expect(
            e.evaluate({ t: 1000, values: { 'room.tempC': 4 } }).cleared,
        ).toHaveLength(0);
        const { cleared } = e.evaluate({
            t: 2000,
            values: { 'room.tempC': 3 },
        });
        expect(cleared).toHaveLength(1);
        expect(cleared[0]!.clearedAt).toBe(2000);
    });

    it('supports less-than rules', () => {
        const e = createRuleEngine(
            baseDef([{ ...rule, op: 'lt', forS: 0, clearAt: 7 }]),
        );
        expect(
            e.evaluate({ t: 0, values: { 'room.tempC': 4 } }).raised,
        ).toHaveLength(1);
        expect(
            e.evaluate({ t: 1, values: { 'room.tempC': 6 } }).cleared,
        ).toHaveLength(0);
        expect(
            e.evaluate({ t: 2, values: { 'room.tempC': 7 } }).cleared,
        ).toHaveLength(1);
    });

    it('answers whether an alert was active at a past time', () => {
        const a = { raisedAt: 10, clearedAt: 20 } as Alert;
        expect(activeAt(a, 5)).toBe(false);
        expect(activeAt(a, 10)).toBe(true);
        expect(activeAt(a, 20)).toBe(false);
    });

    it('acknowledges once, keeping the first time', () => {
        const e = createRuleEngine(baseDef([{ ...rule, forS: 0 }]));
        const [alert] = e.evaluate({
            t: 0,
            values: { 'room.tempC': 9 },
        }).raised;
        e.acknowledge(alert!.id, 5);
        e.acknowledge(alert!.id, 7);
        expect(alert!.ackedAt).toBe(5);
    });

    it('ignores frames without the metric', () => {
        const e = createRuleEngine(baseDef([rule]));
        expect(e.evaluate({ t: 0, values: {} }).raised).toHaveLength(0);
    });
});

describe('history', () => {
    const frame = (t: number, v = t) => ({ t, values: { a: v } });

    it('keeps capacity and overwrites the oldest', () => {
        const h = new History(3);
        [1, 2, 3, 4, 5].forEach((t) => h.push(frame(t)));
        expect(h.length).toBe(3);
        expect(h.first()!.t).toBe(3);
        expect(h.last()!.t).toBe(5);
    });

    it('drops out-of-order frames', () => {
        const h = new History(3);
        h.push(frame(5));
        expect(h.push(frame(5))).toBe(false);
        expect(h.push(frame(4))).toBe(false);
        expect(h.length).toBe(1);
    });

    it('finds the newest frame at or before a time, across the wrap', () => {
        const h = new History(3);
        [10, 20, 30, 40].forEach((t) => h.push(frame(t)));
        expect(h.nearest(35)!.t).toBe(30);
        expect(h.nearest(40)!.t).toBe(40);
        expect(h.nearest(5)!.t).toBe(20);
    });

    it('thins a series to the limit, keeping the newest point', () => {
        const h = new History(1000);
        for (let t = 1; t <= 1000; t++) h.push(frame(t));
        const s = h.series('a', -Infinity, Infinity, 10);
        expect(s).toHaveLength(10);
        expect(s.at(-1)!.t).toBe(1000);
        expect(h.series('a', 100, 110)).toHaveLength(11);
    });
});

describe('geo', () => {
    it('projects metres to degrees at the right scale', () => {
        expect(
            metresToLonLat({ lon: 0, lat: 0 }, [0, 111319.49]).lat,
        ).toBeCloseTo(1, 3);
        expect(
            metresToLonLat({ lon: 0, lat: 60 }, [55659.75, 0]).lon,
        ).toBeCloseTo(1, 3);
    });

    it('walks a closed path and wraps', () => {
        const square: Point[] = [
            [0, 0],
            [10, 0],
            [10, 10],
            [0, 10],
        ];
        expect(pointAlong(square, 15)).toMatchObject({
            x: 10,
            y: 5,
            length: 40,
        });
        expect(pointAlong(square, 45)).toMatchObject({ x: 5, y: 0 });
        expect(pointAlong(square, 5).heading).toBeCloseTo(Math.PI / 2);
    });

    it('finds a centroid', () => {
        expect(
            centroid([
                [0, 0],
                [2, 0],
                [2, 2],
                [0, 2],
            ]),
        ).toEqual([1, 1]);
    });
});

describe('metrics', () => {
    it('formats with unit and handles gaps', () => {
        const temp = METRICS.coldstore.find((m) => m.key === 'tempC');
        expect(formatMetric(temp, -19.96)).toBe('-20.0 °C');
        expect(formatMetric(temp, undefined)).toBe('—');
        expect(formatMetric(temp, NaN)).toBe('—');
        const power = METRICS.grid.find((m) => m.key === 'powerKw');
        expect(formatMetric(power, -0.2)).toBe('0 kW');
        const cost = METRICS.site.find((m) => m.key === 'costPerH');
        expect(formatMetric(cost, 12.5)).toBe('$12.50/h');
    });
});

describe('validation', () => {
    it('accepts a sound definition', () => {
        expect(validateDefinition(baseDef([]))).toEqual([]);
    });

    it('reports every problem at once', () => {
        const def = baseDef([
            {
                id: 'r',
                name: '',
                severity: 'info',
                metric: 'x',
                op: 'gt',
                threshold: 0,
                message: '',
            },
        ]);
        def.origin = { lon: 999, lat: 0 };
        def.assets.push(
            {
                id: 'room',
                name: 'dupe',
                kind: 'bus',
                zone: '',
                position: [0, 0],
                fedBy: 'main',
            },
            {
                id: 'lost',
                name: '',
                kind: 'chiller',
                zone: 'nowhere',
                fedBy: 'ghost',
                cools: ['main'],
            },
        );
        const errors = validateDefinition(def).join('\n');
        expect(errors).toMatch(/origin\.lon/);
        expect(errors).toMatch(/Duplicate asset id room/);
        expect(errors).toMatch(/fedBy unknown asset ghost/);
        expect(errors).toMatch(/cools main/);
        expect(errors).toMatch(/needs a footprint/);
        expect(errors).toMatch(/unknown zone nowhere/);
        expect(errors).toMatch(/needs a kind or an asset/);
    });

    it('catches a feed cycle before it can hang the model', () => {
        const def = baseDef([]);
        def.assets.push(
            {
                id: 'a',
                name: '',
                kind: 'bus',
                zone: '',
                position: [0, 0],
                fedBy: 'b',
            },
            {
                id: 'b',
                name: '',
                kind: 'bus',
                zone: '',
                position: [0, 0],
                fedBy: 'a',
            },
        );
        expect(validateDefinition(def).join()).toMatch(/cycle/);
    });

    it('rejects things that are not objects', () => {
        expect(validateDefinition(null)).toEqual([
            'Definition must be a JSON object',
        ]);
    });
});

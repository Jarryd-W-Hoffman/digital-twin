import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { History } from './history.ts';
import { formatMetric } from './metrics.ts';
import { compileReadout } from './readouts.ts';
import type { TwinDefinition } from './types.ts';
import { validateDefinition } from './validate.ts';

const sample = (name: string) =>
    JSON.parse(
        readFileSync(`public/samples/${name}.json`, 'utf8'),
    ) as TwinDefinition;

describe('readouts', () => {
    it('works out a figure from other assets and formats it', () => {
        const margin = compileReadout({
            label: 'Margin',
            value: '5 - {store.tempC}',
            unit: '°C',
            decimals: 1,
        });
        expect(margin.metric).toMatchObject({
            label: 'Margin',
            unit: '°C',
            decimals: 1,
        });
        expect(margin.value({ 'store.tempC': 3.26 })).toBeCloseTo(1.74, 6);
        expect(
            formatMetric(margin.metric, margin.value({ 'store.tempC': 3.26 })),
        ).toContain('1.7');
    });

    it('has no value while what it reads is missing', () => {
        const r = compileReadout({ label: 'x', value: '{ghost.v} + 1' });
        expect(r.value({})).toBeUndefined();
        expect(
            compileReadout({ label: 'y', value: '1 / 0' }).value({}),
        ).toBeUndefined();
    });

    it('takes a fallback label, as a chart takes its panel title', () => {
        const r = compileReadout({ value: '2' }, 'Pressure');
        expect(r.metric.label).toBe('Pressure');
        expect(r.metric.unit).toBe('');
        expect(r.metric.decimals).toBe(0);
        expect(compileReadout({ value: '2' }).metric.label).toBe('');
    });

    it('charts any expression over history', () => {
        const h = new History(10);
        h.push({ t: 1, values: { 'a.v': 1 } });
        h.push({ t: 2, values: {} });
        h.push({ t: 3, values: { 'a.v': 4 } });
        const r = compileReadout({ label: 'double', value: '{a.v} * 2' });
        expect(h.seriesOf(r.value)).toEqual([
            { t: 1, v: 2 },
            { t: 3, v: 8 },
        ]);
        expect(h.series('a.v', 2)).toEqual([{ t: 3, v: 4 }]);
    });

    it('ships samples with valid KPIs and panels', () => {
        const depot = sample('harbour-depot');
        const northshore = sample('northshore');
        expect(validateDefinition(depot)).toEqual([]);
        expect(validateDefinition(northshore)).toEqual([]);
        expect(depot.kpis).toHaveLength(4);
        expect(northshore.panels?.[0]?.title).toBe('Fire protection');
    });
});

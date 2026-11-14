import { describe, expect, it } from 'vitest';
import {
    guessMetric,
    metricChoices,
    parseCsv,
    seriesOf,
    sniffCsv,
    timeFormatOf,
} from './csv.ts';
import type { TwinDefinition } from './types.ts';

const depot = {
    assets: [
        { id: 'site', kind: 'site', name: 'Depot' },
        { id: 'store', kind: 'coldstore', name: 'Seafood store' },
        {
            id: 'pz1',
            kind: 'custom',
            type: 'piezometer',
            name: 'PZ-01',
        },
    ],
    types: [
        {
            id: 'piezometer',
            name: 'Piezometer',
            metrics: [{ key: 'levelM', label: 'Water level', unit: 'm' }],
        },
    ],
} as unknown as TwinDefinition;

describe('parseCsv', () => {
    it('splits rows and cells, honouring quotes and dropping blank rows', () => {
        expect(
            parseCsv('a,"b, c","say ""hi"""\r\n\r\n 1 ,2,3\n4,5', ','),
        ).toEqual([
            ['a', 'b, c', 'say "hi"'],
            ['1', '2', '3'],
            ['4', '5'],
        ]);
        expect(parseCsv('a;b\n', ';')).toEqual([['a', 'b']]);
    });
});

describe('timeFormatOf', () => {
    it('tells the formats apart, day first when it could be either', () => {
        expect(timeFormatOf(['2026-03-01 06:30', '2026-03-01T06:30:15Z'])).toBe(
            'iso',
        );
        expect(timeFormatOf(['1794333600', '1794333600500'])).toBe('unix');
        expect(timeFormatOf(['01/03/2026 6:30 pm', '02/03/2026'])).toBe('dmy');
        expect(timeFormatOf(['13/03/2026'])).toBe('dmy');
        expect(timeFormatOf(['03/13/2026 06:30', ''])).toBe('mdy');
        expect(timeFormatOf(['yesterday'])).toBeNull();
        expect(timeFormatOf([''])).toBeNull();
    });
});

describe('sniffCsv', () => {
    it('reads a wide file: the time, then a column per series', () => {
        const sniff = sniffCsv(
            '﻿Timestamp,Seafood store Temperature,PZ-01\n2026-03-01 06:00,1.5,12\n2026-03-01 06:30,,12.5\n',
        );
        expect(sniff).toMatchObject({
            delimiter: ',',
            header: ['Timestamp', 'Seafood store Temperature', 'PZ-01'],
            layout: 'wide',
            time: 'Timestamp',
            format: 'iso',
        });
        expect(sniff.rows).toHaveLength(2);
        expect(seriesOf(sniff)).toEqual(['Seafood store Temperature', 'PZ-01']);
    });

    it('reads a long file: a column naming the series, and its value', () => {
        const sniff = sniffCsv(
            'Sensor;Reading;Taken\nPZ-01;12,5;13/03/2026 06:00\nPZ-02;3;13/03/2026 06:00\nPZ-01;12,6;14/03/2026 06:00\n',
        );
        expect(sniff).toMatchObject({
            delimiter: ';',
            layout: 'long',
            time: 'Taken',
            format: 'dmy',
            name: 'Sensor',
            value: 'Reading',
        });
        expect(seriesOf(sniff)).toEqual(['PZ-01', 'PZ-02']);
    });

    it('finds dates in a column without a time-like name, or takes the first', () => {
        expect(sniffCsv('Day\tT\n2026-03-01\t1\n').time).toBe('Day');
        expect(sniffCsv('At|T\n1794333600|1\n', '|')).toMatchObject({
            time: 'At',
            format: 'unix',
        });
        expect(sniffCsv('').header).toEqual([]);
    });
});

describe('guessing metrics', () => {
    const choices = metricChoices(depot);

    it('offers the metrics of every asset but the site', () => {
        expect(choices.map((c) => c.value)).toContain('store|tempC');
        expect(choices.map((c) => c.value)).toContain('pz1|levelM');
        expect(choices.some((c) => c.asset === 'site')).toBe(false);
    });

    it('matches a series by asset and metric, or by an asset with one metric', () => {
        expect(guessMetric('store.tempC', choices, depot)).toBe('store|tempC');
        expect(guessMetric('Seafood store · Temperature', choices, depot)).toBe(
            'store|tempC',
        );
        expect(guessMetric('PZ-01', choices, depot)).toBe('pz1|levelM');
        expect(guessMetric('Seafood store', choices, depot)).toBe('');
        expect(guessMetric('Rain', choices, depot)).toBe('');
    });
});

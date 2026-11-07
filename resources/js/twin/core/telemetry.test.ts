import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { compileTelemetry, topicMatches, valueFrom } from './telemetry.ts';
import type { TelemetryMapping } from './types.ts';
import { parseDefinition } from './validate.ts';

const harbour = (telemetry: TelemetryMapping[]) =>
    parseDefinition({
        ...JSON.parse(
            readFileSync('public/samples/harbour-depot.json', 'utf8'),
        ),
        telemetry,
    });

describe('topic filters', () => {
    it.each([
        ['a/b', 'a/b', true],
        ['a/b', 'a/c', false],
        ['a/+', 'a/b', true],
        ['a/+', 'a/b/c', false],
        ['a/+/c', 'a/b/c', true],
        ['a/#', 'a', true],
        ['a/#', 'a/b/c', true],
        ['#', 'anything/at/all', true],
        ['a/b', 'a', false],
        ['a/b/c', 'a/b', false],
    ])('%s against %s is %s', (filter, topic, matches) => {
        expect(topicMatches(filter, topic)).toBe(matches);
    });
});

describe('payload values', () => {
    it.each([
        ['21.5', undefined, 21.5],
        [' -4 ', undefined, -4],
        ['{"value": 3}', undefined, 3],
        ['{"value": "3.5"}', undefined, 3.5],
        ['{"data": {"kw": 1200}}', 'data.kw', 1200],
        ['{"data": [1]}', 'data.kw', null],
        ['{"data": {"kw": "n/a"}}', 'data.kw', null],
        ['{"other": 1}', undefined, null],
        ['[1, 2]', undefined, null],
        ['on', undefined, null],
        ['{"value": ""}', undefined, null],
        ['1e999', undefined, null],
    ])('%s at %s is %s', (payload, path, value) => {
        expect(valueFrom(payload, path)).toBe(value);
    });
});

describe('compiled mappings', () => {
    it('subscribes to each filter once and maps, scales and offsets readings', () => {
        const telemetry = compileTelemetry(
            harbour([
                {
                    topic: 'harbour/store/temp',
                    asset: 'store',
                    metric: 'tempC',
                    ttlS: 60,
                },
                {
                    topic: 'harbour/+/power',
                    asset: 'plant',
                    metric: 'powerKw',
                    path: 'w',
                    scale: 0.001,
                },
                {
                    topic: 'harbour/+/power',
                    asset: 'board',
                    metric: 'loadKw',
                    path: 'w',
                    scale: 0.001,
                    offset: 2,
                },
            ]),
        );

        expect(telemetry.topics).toEqual([
            'harbour/store/temp',
            'harbour/+/power',
        ]);
        expect(telemetry.skipped).toEqual([]);
        expect(telemetry.read('harbour/store/temp', '-18.2')).toEqual({
            readings: [
                { asset: 'store', metric: 'tempC', value: -18.2, ttlS: 60 },
            ],
            problems: [],
        });
        expect(
            telemetry.read('harbour/plant/power', '{"w": 45000}').readings,
        ).toEqual([
            { asset: 'plant', metric: 'powerKw', value: 45 },
            { asset: 'board', metric: 'loadKw', value: 47 },
        ]);
        expect(telemetry.read('harbour/unmapped', '1')).toEqual({
            readings: [],
            problems: [],
        });
    });

    it('says why a matching message gave no reading', () => {
        const telemetry = compileTelemetry(
            harbour([
                { topic: 't', asset: 'store', metric: 'tempC', path: 'a.b' },
            ]),
        );

        expect(telemetry.read('t', '{"a": {}}')).toEqual({
            readings: [],
            problems: ['t: no number at a.b for store.tempC'],
        });
    });

    it('skips mappings onto metrics the asset does not have', () => {
        const telemetry = compileTelemetry(
            harbour([
                { topic: 't', asset: 'store', metric: 'rpm' },
                { topic: 'u', asset: 'store', metric: 'tempC' },
            ]),
        );

        expect(telemetry.skipped).toEqual([
            'Telemetry t: store has no metric rpm',
        ]);
        expect(telemetry.topics).toEqual(['u']);
    });

    it("places topics below the project's prefix", () => {
        const telemetry = compileTelemetry(
            harbour([
                { topic: 'store/+/temp', asset: 'store', metric: 'tempC' },
            ]),
            'p/harbour-x1y2/',
        );

        expect(telemetry.topics).toEqual(['p/harbour-x1y2/store/+/temp']);
        expect(
            telemetry.read('p/harbour-x1y2/store/a/temp', '-18').readings,
        ).toEqual([{ asset: 'store', metric: 'tempC', value: -18 }]);
        expect(telemetry.read('store/a/temp', '-18').readings).toEqual([]);
        expect(
            telemetry.read('p/other-z9z9/store/a/temp', '-18').readings,
        ).toEqual([]);
    });
});

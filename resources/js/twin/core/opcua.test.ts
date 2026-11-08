import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { compileOpcua, DEFAULT_SAMPLING_MS, numberFrom } from './opcua.ts';
import type { OpcUaMapping } from './types.ts';
import { parseDefinition } from './validate.ts';

const harbour = (opcua: OpcUaMapping[]) =>
    parseDefinition({
        ...JSON.parse(
            readFileSync('public/samples/harbour-depot.json', 'utf8'),
        ),
        opcua,
    });

describe('OPC UA values', () => {
    it.each([
        [21.5, 21.5],
        [-4, -4],
        [true, 1],
        [false, 0],
        [12n, 12],
        [[0, 5], 5],
        [[1, 0], 2 ** 32],
        [[0, -1], 2 ** 32 - 1],
        ['3.5', 3.5],
        ['', null],
        ['n/a', null],
        [Number.NaN, null],
        [null, null],
        [{ value: 1 }, null],
        [[1, 2, 3], null],
    ])('%s is %s', (value, expected) => {
        expect(numberFrom(value)).toBe(expected);
    });
});

describe('mappings', () => {
    it('monitors each node once per server, at the fastest sampling asked for', () => {
        const opcua = compileOpcua(
            harbour([
                {
                    server: 'PLC',
                    node: 'ns=2;s=Store.TempC',
                    asset: 'store',
                    metric: 'tempC',
                    samplingMs: 2000,
                },
                {
                    server: 'PLC',
                    node: 'ns=2;s=Store.TempC',
                    asset: 'store',
                    metric: 'setpointC',
                    samplingMs: 500,
                },
                {
                    server: 'PLC',
                    node: 'ns=2;s=Store.Door',
                    asset: 'store',
                    metric: 'doorOpen',
                },
                {
                    server: 'Meter',
                    node: 'i=2258',
                    asset: 'store',
                    metric: 'tempC',
                },
            ]),
            ['PLC', 'Meter'],
        );

        expect(opcua.skipped).toEqual([
            'OPC UA ns=2;s=Store.Door: store has no metric doorOpen',
        ]);
        expect([...opcua.servers.keys()]).toEqual(['PLC', 'Meter']);
        expect(
            opcua.servers
                .get('PLC')!
                .map((n) => [n.node, n.samplingMs, n.mappings.length]),
        ).toEqual([['ns=2;s=Store.TempC', 500, 2]]);
        expect(opcua.servers.get('Meter')![0]!.samplingMs).toBe(
            DEFAULT_SAMPLING_MS,
        );
    });

    it('skips mappings to servers the project has none of', () => {
        const opcua = compileOpcua(
            harbour([
                {
                    server: 'Gone',
                    node: 'i=1',
                    asset: 'store',
                    metric: 'tempC',
                },
            ]),
            ['PLC'],
        );

        expect(opcua.servers.size).toBe(0);
        expect(opcua.skipped).toEqual(['OPC UA i=1: no server named Gone']);
    });

    it('scales and offsets a value into each metric it feeds', () => {
        const opcua = compileOpcua(
            harbour([
                {
                    server: 'PLC',
                    node: 'ns=2;s=T',
                    asset: 'store',
                    metric: 'tempC',
                    scale: 0.1,
                    offset: -1,
                    ttlS: 30,
                },
                {
                    server: 'PLC',
                    node: 'ns=2;s=T',
                    asset: 'store',
                    metric: 'setpointC',
                },
            ]),
            ['PLC'],
        );

        expect(opcua.read('PLC', 'ns=2;s=T', 215)).toEqual({
            readings: [
                { asset: 'store', metric: 'tempC', value: 20.5, ttlS: 30 },
                { asset: 'store', metric: 'setpointC', value: 215 },
            ],
            problems: [],
        });
        expect(opcua.read('PLC', 'ns=2;s=T', 'off')).toEqual({
            readings: [],
            problems: ['OPC UA ns=2;s=T on PLC: not a number'],
        });
        expect(opcua.read('PLC', 'ns=2;s=Other', 1)).toEqual({
            readings: [],
            problems: [],
        });
        expect(opcua.read('Other', 'ns=2;s=T', 1)).toEqual({
            readings: [],
            problems: [],
        });
    });
});

describe('writing setpoints', () => {
    const northshore = (opcua: OpcUaMapping[]) =>
        parseDefinition({
            ...JSON.parse(
                readFileSync('public/samples/northshore.json', 'utf8'),
            ),
            opcua,
        });

    it('writes to every written mapping of the metric, clamped and unscaled', () => {
        const opcua = compileOpcua(
            northshore([
                // The PLC holds the start pressure in bar: kPa × 0.01.
                {
                    server: 'PLC',
                    node: 'ns=2;s=Jockey.Start',
                    asset: 'jockey',
                    metric: 'startKpa',
                    scale: 100,
                    write: true,
                },
                {
                    server: 'PLC',
                    node: 'ns=2;s=Jockey.StartEcho',
                    asset: 'jockey',
                    metric: 'startKpa',
                },
                {
                    server: 'Spare',
                    node: 'ns=2;s=Jockey.Start',
                    asset: 'jockey',
                    metric: 'startKpa',
                    offset: 50,
                    write: true,
                },
                {
                    server: 'Nowhere',
                    node: 'ns=2;s=X',
                    asset: 'jockey',
                    metric: 'startKpa',
                    write: true,
                },
            ]),
            ['PLC', 'Spare'],
        );

        expect(opcua.writes('jockey', 'startKpa', 600)).toEqual([
            {
                server: 'PLC',
                node: 'ns=2;s=Jockey.Start',
                asset: 'jockey',
                metric: 'startKpa',
                requested: 600,
                setpoint: 600,
                value: 6,
            },
            {
                server: 'Spare',
                node: 'ns=2;s=Jockey.Start',
                asset: 'jockey',
                metric: 'startKpa',
                requested: 600,
                setpoint: 600,
                value: 550,
            },
        ]);
        expect(opcua.writes('jockey', 'startKpa', 9000)[0]).toMatchObject({
            requested: 9000,
            setpoint: 720,
            value: 7.2,
        });
        expect(opcua.writes('jockey', 'startKpa', 100)[0]).toMatchObject({
            setpoint: 550,
            value: 5.5,
        });
    });

    it('writes nothing that is not a mapped, written setpoint', () => {
        const opcua = compileOpcua(
            northshore([
                {
                    server: 'PLC',
                    node: 'ns=2;s=Jockey.Stop',
                    asset: 'jockey',
                    metric: 'stopKpa',
                },
            ]),
            ['PLC'],
        );

        expect(opcua.writes('jockey', 'stopKpa', 700)).toEqual([]);
        expect(opcua.writes('jockey', 'pressureKpa', 700)).toEqual([]);
        expect(opcua.writes('freezer', 'setpointC', -20)).toEqual([]);
        expect(opcua.writes('ghost', 'startKpa', 600)).toEqual([]);
        expect(opcua.writes('jockey', 'startKpa', Number.NaN)).toEqual([]);
    });
});

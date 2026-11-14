import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { compileModbus, decode, DEFAULT_POLL_MS, widthOf } from './modbus.ts';
import type { ModbusMapping } from './types.ts';
import { parseDefinition, validateDefinition } from './validate.ts';

const harbour = (modbus: ModbusMapping[]) =>
    parseDefinition({
        ...JSON.parse(
            readFileSync('public/samples/harbour-depot.json', 'utf8'),
        ),
        modbus,
    });

describe('decoding registers', () => {
    it.each([
        [[0xfffe], 'uint16', 'big', 65534],
        [[0xfffe], 'int16', 'big', -2],
        [[0x0001, 0x0000], 'uint32', 'big', 65536],
        [[0x0000, 0x0001], 'uint32', 'little', 65536],
        [[0xffff, 0xfffe], 'int32', 'big', -2],
        [[0x41c8, 0x0000], 'float32', 'big', 25],
        [[0x0000, 0x41c8], 'float32', 'little', 25],
        [[0x4039, 0x0000, 0x0000, 0x0000], 'float64', 'big', 25],
        [[0x0000, 0x0000, 0x0000, 0x4039], 'float64', 'little', 25],
        [[7], 'bool', 'big', 1],
        [[0], 'bool', 'big', 0],
    ] as const)('%j as %s (%s) is %s', (registers, type, order, expected) => {
        expect(decode([...registers], type, order)).toBe(expected);
    });

    it('knows how many registers each type takes', () => {
        expect(
            [
                'uint16',
                'int16',
                'bool',
                'uint32',
                'int32',
                'float32',
                'float64',
            ].map((t) => widthOf(t as never)),
        ).toEqual([1, 1, 1, 2, 2, 2, 4]);
    });
});

describe('mappings', () => {
    it('reads neighbouring registers together, apart by table and rate, and skips what it cannot map', () => {
        const modbus = compileModbus(
            harbour([
                {
                    device: 'Meter',
                    address: 10,
                    type: 'float32',
                    asset: 'grid',
                    metric: 'powerKw',
                },
                {
                    device: 'Meter',
                    address: 0,
                    asset: 'board',
                    metric: 'loadKw',
                    scale: 0.1,
                },
                {
                    device: 'Meter',
                    address: 26,
                    type: 'int16',
                    asset: 'plant',
                    metric: 'powerKw',
                },
                {
                    device: 'Meter',
                    address: 200,
                    asset: 'plant',
                    metric: 'powerKw',
                },
                {
                    device: 'Meter',
                    table: 'input',
                    address: 0,
                    asset: 'store',
                    metric: 'tempC',
                    pollMs: 5000,
                },
                {
                    device: 'Meter',
                    table: 'coil',
                    address: 3,
                    asset: 'store',
                    metric: 'doorPct',
                },
                { device: 'PLC', address: 0, asset: 'store', metric: 'tempC' },
                {
                    device: 'Meter',
                    address: 5,
                    asset: 'store',
                    metric: 'noSuch',
                },
                {
                    device: 'Meter',
                    address: 65535,
                    type: 'uint32',
                    asset: 'store',
                    metric: 'tempC',
                },
            ]),
            ['Meter'],
        );

        expect(modbus.skipped).toEqual([
            'Modbus PLC holding 0: no device named PLC',
            'Modbus Meter holding 5: store has no metric noSuch',
            'Modbus Meter holding 65535: runs past the last address',
        ]);
        expect(
            modbus.devices
                .get('Meter')!
                .map((r) => [
                    r.table,
                    r.start,
                    r.count,
                    r.pollMs,
                    r.mappings.length,
                ]),
        ).toEqual([
            ['holding', 0, 27, DEFAULT_POLL_MS, 3],
            ['holding', 200, 1, DEFAULT_POLL_MS, 1],
            ['input', 0, 1, 5000, 1],
            ['coil', 3, 1, DEFAULT_POLL_MS, 1],
        ]);
    });

    it('splits a run longer than one request may ask for', () => {
        const modbus = compileModbus(
            harbour(
                Array.from({ length: 70 }, (_, i) => ({
                    device: 'Meter',
                    address: i * 2,
                    type: 'float32' as const,
                    asset: 'store',
                    metric: 'tempC',
                })),
            ),
            ['Meter'],
        );
        expect(
            modbus.devices.get('Meter')!.map((r) => [r.start, r.count]),
        ).toEqual([
            [0, 124],
            [124, 16],
        ]);
    });

    it('turns an answer into readings, scaled, with bits and their holding time', () => {
        const modbus = compileModbus(
            harbour([
                {
                    device: 'Meter',
                    address: 0,
                    asset: 'board',
                    metric: 'loadKw',
                    scale: 0.1,
                    offset: 2,
                    ttlS: 30,
                },
                {
                    device: 'Meter',
                    address: 1,
                    type: 'float32',
                    wordOrder: 'little',
                    asset: 'grid',
                    metric: 'powerKw',
                },
                {
                    device: 'Meter',
                    address: 3,
                    bit: 2,
                    asset: 'store',
                    metric: 'doorPct',
                },
                {
                    device: 'Meter',
                    table: 'discrete',
                    address: 0,
                    asset: 'plant',
                    metric: 'powerKw',
                },
            ]),
            ['Meter'],
        );
        const [holding, discrete] = modbus.devices.get('Meter')!;

        expect(
            modbus.read('Meter', holding, [1234, 0x0000, 0x41c8, 0b0100]),
        ).toEqual({
            readings: [
                { asset: 'board', metric: 'loadKw', value: 125.4, ttlS: 30 },
                { asset: 'grid', metric: 'powerKw', value: 25 },
                { asset: 'store', metric: 'doorPct', value: 1 },
            ],
            problems: [],
        });
        expect(modbus.read('Meter', discrete, [1]).readings).toEqual([
            { asset: 'plant', metric: 'powerKw', value: 1 },
        ]);
        expect(modbus.read('Meter', holding, [1234, 0]).problems).toEqual([
            'Modbus Meter holding 1: the device sent too little',
            'Modbus Meter holding 3: the device sent too little',
        ]);
        expect(
            modbus.read('Meter', holding, [1234, 0xffff, 0x7fff, 0]).problems,
        ).toEqual(['Modbus Meter holding 1: not a number']);
    });

    it('is checked in the definition', () => {
        const def = JSON.parse(
            readFileSync('public/samples/harbour-depot.json', 'utf8'),
        );
        expect(
            validateDefinition({
                ...def,
                modbus: [
                    {
                        device: 'Meter',
                        address: 1.5,
                        asset: 'store',
                        metric: 'tempC',
                    },
                    { device: '', address: 2, asset: 'store', metric: 'tempC' },
                    {
                        device: 'Meter',
                        address: 3,
                        table: 'register',
                        type: 'char',
                        wordOrder: 'middle',
                        bit: 16,
                        asset: 'pump',
                        metric: 'Temp',
                        pollMs: 10,
                    },
                ],
            }),
        ).toEqual([
            "A Modbus mapping's address must be a whole number from 0 to 65535",
            'Modbus 2: needs a device',
            'Modbus 3: bit must be from 0 to 15',
            'Modbus 3: metric must be a camelCase identifier',
            'Modbus 3: pollMs must be at least 100',
            'Modbus 3: table must be holding, input, coil or discrete',
            'Modbus 3: type must be uint16, int16, uint32, int32, float32, float64 or bool',
            'Modbus 3: wordOrder must be big or little',
            'Modbus 3: unknown asset pump',
        ]);
    });
});

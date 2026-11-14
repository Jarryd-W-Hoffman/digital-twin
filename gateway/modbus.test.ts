import { readFileSync } from 'node:fs';
import { createServer, type Server } from 'node:http';
import { createServer as createTcpServer, type Socket } from 'node:net';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { compileModbus } from '../resources/js/twin/core/modbus.ts';
import type { ModbusMapping } from '../resources/js/twin/core/types.ts';
import { parseDefinition } from '../resources/js/twin/core/validate.ts';
import { standaloneDevices, startGateway } from './gateway.ts';
import { startModbusSimulator } from './modbus-simulator.ts';
import {
    ModbusClient,
    ModbusPoller,
    requestFrame,
    type ModbusDeviceConfig,
} from './modbus.ts';
import { freePort } from './opcua-simulator.ts';

const sample = JSON.parse(
    readFileSync('public/samples/harbour-depot.json', 'utf8'),
) as Record<string, unknown>;

const mappings: ModbusMapping[] = [
    {
        device: 'Meter',
        address: 0,
        type: 'float32',
        asset: 'grid',
        metric: 'powerKw',
        pollMs: 100,
    },
    {
        device: 'Meter',
        table: 'input',
        address: 0,
        asset: 'store',
        metric: 'tempC',
        scale: 0.1,
        pollMs: 100,
    },
    {
        device: 'Meter',
        address: 900,
        asset: 'board',
        metric: 'loadKw',
        pollMs: 100,
    },
    {
        device: 'Meter',
        address: 998,
        type: 'float64',
        asset: 'board',
        metric: 'loadKw',
        pollMs: 100,
    },
];

const cleanups: (() => Promise<unknown> | void)[] = [];
afterEach(async () => {
    while (cleanups.length) await cleanups.pop()!();
});

async function simulator(unitId = 1) {
    const sim = await startModbusSimulator({ unitId });
    cleanups.push(() => sim.close());
    return sim;
}

const device = (port: number, unitId = 1): ModbusDeviceConfig => ({
    name: 'Meter',
    host: '127.0.0.1',
    port,
    unitId,
});

describe('the Modbus TCP client', { timeout: 20_000 }, () => {
    it('frames a read request', () => {
        expect([...requestFrame(0x0102, 7, 'input', 300, 4)]).toEqual([
            1, 2, 0, 0, 0, 6, 7, 4, 1, 44, 0, 4,
        ]);
    });

    it('reads registers and bits, one request at a time', async () => {
        const sim = await simulator();
        sim.set('holding', 10, [1, 0xffff, 42]);
        sim.set('input', 0, [7]);
        sim.set('coil', 3, [1, 0, 1]);
        sim.set('discrete', 9, [1]);
        const client = new ModbusClient(device(sim.port));
        cleanups.push(() => client.close());
        await client.connect();

        const [holding, input, coils, discrete] = await Promise.all([
            client.read('holding', 10, 3),
            client.read('input', 0, 1),
            client.read('coil', 3, 3),
            client.read('discrete', 8, 2),
        ]);
        expect(holding).toEqual([1, 0xffff, 42]);
        expect(input).toEqual([7]);
        expect(coils).toEqual([1, 0, 1]);
        expect(discrete).toEqual([0, 1]);
        expect(sim.requests).toBe(4);
    });

    it('says why a device refused, and when it is not connected', async () => {
        const sim = await simulator();
        const client = new ModbusClient(device(sim.port));
        cleanups.push(() => client.close());
        await expect(client.read('holding', 0, 1)).rejects.toThrow(
            'not connected',
        );
        await client.connect();

        await expect(client.read('holding', 999, 2)).rejects.toThrow(
            'illegal data address',
        );
        await expect(client.read('holding', 0, 200)).rejects.toThrow(
            'illegal data value',
        );
        const other = new ModbusClient(device(sim.port, 9));
        cleanups.push(() => other.close());
        await other.connect();
        await expect(other.read('holding', 0, 1)).rejects.toThrow(
            'gateway target failed to respond',
        );
    });

    it('fails to connect where nothing listens, and notices a dropped connection', async () => {
        const client = new ModbusClient(device(await freePort()));
        await expect(client.connect()).rejects.toThrow();

        const sim = await simulator();
        const live = new ModbusClient(device(sim.port));
        cleanups.push(() => live.close());
        const dropped = new Promise<Error>(
            (resolve) => (live.onClose = resolve),
        );
        await live.connect();
        sim.drop();
        expect((await dropped).message).toBe(
            'the device closed the connection',
        );
    });

    it('gives up on a device that does not answer', async () => {
        // Accepts the connection and says nothing.
        const held = new Set<Socket>();
        const silent = createTcpServer((socket) => held.add(socket));
        const port = await freePort();
        await new Promise<void>((resolve) =>
            silent.listen(port, '127.0.0.1', resolve),
        );
        cleanups.push(
            () =>
                new Promise((resolve) => {
                    for (const socket of held) socket.destroy();
                    silent.close(resolve);
                }),
        );
        const client = new ModbusClient(device(port));
        cleanups.push(() => client.close());
        await client.connect();
        await expect(client.read('holding', 0, 1)).rejects.toThrow(
            'no answer within 3 s',
        );
    });
});

describe('polling devices', { timeout: 30_000 }, () => {
    const compiled = () =>
        compileModbus(parseDefinition({ ...sample, modbus: mappings }), [
            'Meter',
        ]).devices;

    it('asks for each range at its rate, says which it was refused, and reconnects after a drop', async () => {
        const sim = await simulator();
        sim.setFloat('holding', 0, 42.5);
        sim.set('input', 0, [12]);
        const seen: { device: string; start: number; values: number[] }[] = [];
        const poller = new ModbusPoller((name, read, values) =>
            seen.push({ device: name, start: read.start, values }),
        );
        cleanups.push(() => poller.close());

        poller.follow([device(sim.port)], compiled());
        await vi.waitFor(() => {
            expect(
                seen.some((s) => s.start === 0 && s.values[0] === 0x422a),
            ).toBe(true);
            expect(
                seen.some(
                    (s) =>
                        s.start === 0 &&
                        s.values.length === 1 &&
                        s.values[0] === 12,
                ),
            ).toBe(true);
        });
        await vi.waitFor(() =>
            expect(poller.health()).toEqual({
                Meter: {
                    status: 'connected',
                    reads: 4,
                    badReads: { 'holding 998-1001': 'illegal data address' },
                },
            }),
        );

        sim.drop();
        await vi.waitFor(() =>
            expect(poller.health().Meter.status).toBe('reconnecting'),
        );
        const before = seen.length;
        await vi.waitFor(
            () => expect(poller.health().Meter.status).toBe('connected'),
            { timeout: 5000 },
        );
        await vi.waitFor(() => expect(seen.length).toBeGreaterThan(before));

        // Unmapped: the poller lets the device go.
        poller.follow([device(sim.port)], new Map());
        expect(poller.health()).toEqual({});
    });

    it('reports a device it cannot reach, and reconnects when its settings change', async () => {
        const poller = new ModbusPoller(() => undefined);
        cleanups.push(() => poller.close());
        const wanted = compiled();
        poller.follow([device(await freePort())], wanted);
        await vi.waitFor(() =>
            expect(poller.health().Meter).toMatchObject({
                status: 'failed',
                reads: 4,
            }),
        );
        expect(poller.health().Meter.error).toBeTruthy();

        const sim = await simulator();
        poller.follow([device(sim.port)], wanted);
        await vi.waitFor(() =>
            expect(poller.health().Meter.status).toBe('connected'),
        );
        poller.follow([], wanted);
        expect(poller.health()).toEqual({});
    });
});

describe('Modbus through the gateway', { timeout: 30_000 }, () => {
    it('measures mapped registers from the project’s devices, reports them, and follows changes', async () => {
        const sim = await simulator();
        sim.setFloat('holding', 0, 42.5);
        sim.set('input', 0, [12]);
        const state = {
            version: 1,
            devices: standaloneDevices(
                JSON.stringify([
                    { name: 'Meter', host: '127.0.0.1', port: sim.port },
                ]),
            ),
            checkIns: [] as (string | undefined)[],
        };
        const server: Server = createServer((req, res) => {
            if (req.method === 'POST') {
                req.resume();
                req.on('end', () =>
                    res
                        .writeHead(202, { 'content-type': 'application/json' })
                        .end('{}'),
                );
                return;
            }
            state.checkIns.push(
                req.headers['x-gateway-modbus'] as string | undefined,
            );
            const etag = `"v${state.version}-${state.devices.length}"`;
            if (req.headers['if-none-match'] === etag)
                return void res.writeHead(304, { etag }).end();
            res.writeHead(200, {
                'content-type': 'application/json',
                etag,
            }).end(
                JSON.stringify({
                    twin: { id: 'twin-1' },
                    version: state.version,
                    definition: { ...sample, modbus: mappings.slice(0, 2) },
                    mqtt: null,
                    modbus: { devices: state.devices },
                }),
            );
        });
        await new Promise<void>((resolve) =>
            server.listen(0, '127.0.0.1', resolve),
        );
        cleanups.push(() => void server.close());
        const url = `http://127.0.0.1:${(server.address() as { port: number }).port}`;
        const gateway = await startGateway({
            controlPlane: {
                url,
                token: 'mgw_test-token',
                pollMs: 100,
                flushMs: 60_000,
            },
            port: 0,
        });
        cleanups.push(() => gateway.close());
        const measure = vi.spyOn(gateway.runner, 'measure');

        await vi.waitFor(() => {
            expect(measure).toHaveBeenCalledWith(
                'grid.powerKw',
                42.5,
                undefined,
            );
            expect(measure).toHaveBeenCalledWith(
                'store.tempC',
                1.2000000000000002,
                undefined,
            );
        });
        await vi.waitFor(() =>
            expect(state.checkIns.at(-1)).toBe('{"Meter":"connected"}'),
        );
        const health = await (
            await fetch(`http://127.0.0.1:${gateway.port}/health`)
        ).json();
        expect(health.modbus).toMatchObject({
            devices: { Meter: { status: 'connected', reads: 2 } },
            unmapped: 0,
        });

        // The device is removed from the project: the gateway lets it go.
        state.devices = [];
        await vi.waitFor(async () => {
            const after = await (
                await fetch(`http://127.0.0.1:${gateway.port}/health`)
            ).json();
            expect(after.modbus.devices).toEqual({});
        });
    });

    it('reads devices from MODBUS_DEVICES without a control plane', () => {
        expect(
            standaloneDevices(
                '[{"name": "Meter", "host": "10.0.0.5"}, {"name": "Drive", "host": "10.0.0.6", "port": 1502, "unitId": 3}]',
            ),
        ).toEqual([
            { name: 'Meter', host: '10.0.0.5', port: 502, unitId: 1 },
            { name: 'Drive', host: '10.0.0.6', port: 1502, unitId: 3 },
        ]);
    });
});

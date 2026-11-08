import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DataType } from 'node-opcua-client';
import { OpcUaBridge, type OpcUaServerConfig, variantFor } from './opcua.ts';
import { startSimulator } from './opcua-simulator.ts';

/** Against a real OPC UA server, in this process. */

const cleanups: (() => Promise<unknown>)[] = [];
afterEach(async () => {
  while (cleanups.length) await cleanups.pop()!();
});

let port = 48_400;
async function simulator(users?: Record<string, string>) {
  const sim = await startSimulator({ port: port++, users });
  cleanups.push(sim.stop);
  return sim;
}

function bridge() {
  const values: [string, string, unknown][] = [];
  const opcua = new OpcUaBridge((server, node, value) => values.push([server, node, value]), {
    pkiDir: mkdtempSync(join(tmpdir(), 'meridian-gateway-pki-')),
  });
  cleanups.push(() => opcua.close());
  return { opcua, values };
}

const server = (endpoint: string, extra: Partial<OpcUaServerConfig> = {}): OpcUaServerConfig => ({
  name: 'Plant PLC',
  endpoint,
  securityMode: 'none',
  securityPolicy: 'None',
  username: null,
  password: null,
  ...extra,
});

const nodes = (...list: [string, number?][]) =>
  new Map([['Plant PLC', list.map(([node, samplingMs = 100]) => ({ node, samplingMs, mappings: [] }))]]);

describe('the OPC UA bridge', { timeout: 30_000 }, () => {
  it('monitors mapped nodes and hands on their values as they change', async () => {
    const sim = await simulator();
    const { opcua, values } = bridge();

    await opcua.follow([server(sim.endpoint)], nodes(['ns=1;s=Store.TempC'], ['ns=1;s=Store.DoorOpen']));
    await vi.waitFor(() => expect(opcua.health()['Plant PLC']).toMatchObject({ status: 'connected', nodes: 2 }), { timeout: 15_000 });
    await vi.waitFor(() => expect(values).toContainEqual(['Plant PLC', 'ns=1;s=Store.TempC', -18.5]), { timeout: 10_000 });
    expect(values).toContainEqual(['Plant PLC', 'ns=1;s=Store.DoorOpen', false]);

    sim.set('Store.TempC', -12.25);
    await vi.waitFor(() => expect(values).toContainEqual(['Plant PLC', 'ns=1;s=Store.TempC', -12.25]), { timeout: 10_000 });
  });

  it('follows changed mappings, and reports nodes the server refuses', async () => {
    const sim = await simulator();
    const { opcua, values } = bridge();
    await opcua.follow([server(sim.endpoint)], nodes(['ns=1;s=Store.TempC']));
    await vi.waitFor(() => expect(opcua.health()['Plant PLC']?.status).toBe('connected'), { timeout: 15_000 });

    await opcua.follow([server(sim.endpoint)], nodes(['ns=1;s=Meter.EnergyWh'], ['ns=1;s=No.Such']));
    sim.set('Meter.EnergyWh', 1250);
    await vi.waitFor(() => expect(values).toContainEqual(['Plant PLC', 'ns=1;s=Meter.EnergyWh', 1250]), { timeout: 10_000 });
    await vi.waitFor(() => expect(opcua.health()['Plant PLC']?.badNodes).toEqual({ 'ns=1;s=No.Such': expect.stringContaining('BadNodeIdUnknown') }), { timeout: 10_000 });

    values.length = 0;
    sim.set('Store.TempC', 4);
    await new Promise((done) => setTimeout(done, 1500));
    expect(values.filter(([, node]) => node === 'ns=1;s=Store.TempC')).toEqual([]);

    await opcua.follow([server(sim.endpoint)], new Map());
    expect(opcua.health()).toEqual({});
  });

  it('logs in with a username and password when the server asks for one', async () => {
    const sim = await simulator({ meridian: 'secret' });
    const { opcua, values } = bridge();

    await opcua.follow([server(sim.endpoint, { username: 'meridian', password: 'wrong' })], nodes(['ns=1;s=Store.TempC']));
    await vi.waitFor(() => expect(opcua.health()['Plant PLC']).toMatchObject({ status: 'failed', error: expect.stringMatching(/BadUserAccessDenied|BadIdentityToken/) }), { timeout: 15_000 });

    await opcua.follow([server(sim.endpoint, { username: 'meridian', password: 'secret' })], nodes(['ns=1;s=Store.TempC']));
    await vi.waitFor(() => expect(values).toContainEqual(['Plant PLC', 'ns=1;s=Store.TempC', -18.5]), { timeout: 15_000 });
    expect(opcua.health()['Plant PLC']?.status).toBe('connected');
  });

  it('keeps trying a server that is not there yet, and leaves servers with nothing mapped alone', async () => {
    const { opcua } = bridge();

    await opcua.follow(
      [server('opc.tcp://127.0.0.1:1/none'), { ...server('opc.tcp://127.0.0.1:2'), name: 'Unused' }],
      nodes(['ns=1;s=Store.TempC']),
    );
    await new Promise((done) => setTimeout(done, 1500));

    expect(opcua.health()).toEqual({ 'Plant PLC': { status: 'connecting', nodes: 0 } });
  });
});

describe('writing to OPC UA', { timeout: 30_000 }, () => {
  it('writes a setpoint as the node’s own type, rounding integers', async () => {
    const sim = await simulator();
    const { opcua, values } = bridge();
    await opcua.follow([server(sim.endpoint)], nodes(['ns=1;s=Pump.StartBar'], ['ns=1;s=Fan.SpeedPct']));
    await vi.waitFor(() => expect(opcua.health()['Plant PLC']?.status).toBe('connected'), { timeout: 15_000 });

    await opcua.write('Plant PLC', 'ns=1;s=Pump.StartBar', -21.5);
    await opcua.write('Plant PLC', 'ns=1;s=Fan.SpeedPct', 72.6);

    expect(sim.writes).toEqual([
      { node: 'Pump.StartBar', value: -21.5 },
      { node: 'Fan.SpeedPct', value: 73 },
    ]);
    // And it reads back, as the PLC holds it.
    await vi.waitFor(() => expect(values).toContainEqual(['Plant PLC', 'ns=1;s=Pump.StartBar', -21.5]), { timeout: 10_000 });
  });

  it('refuses what the node can’t take, and servers it isn’t connected to', async () => {
    const sim = await simulator();
    const { opcua } = bridge();
    await opcua.follow([server(sim.endpoint)], nodes(['ns=1;s=Fan.SpeedPct']));
    await vi.waitFor(() => expect(opcua.health()['Plant PLC']?.status).toBe('connected'), { timeout: 15_000 });

    await expect(opcua.write('Plant PLC', 'ns=1;s=Fan.SpeedPct', 70_000)).rejects.toThrow('70000 is out of range for UInt16');
    await expect(opcua.write('Plant PLC', 'ns=1;s=Store.Status', 1)).rejects.toThrow("can't write a number to a String node");
    await expect(opcua.write('Plant PLC', 'ns=1;s=No.Such', 1)).rejects.toThrow('BadNodeIdUnknown');
    await expect(opcua.write('Elsewhere', 'ns=1;s=Fan.SpeedPct', 1)).rejects.toThrow('not connected to Elsewhere');
    expect(sim.writes).toEqual([]);
  });
});

describe('values for a node’s type', () => {
  it.each([
    [DataType.Boolean, 1, true],
    [DataType.Boolean, 0, false],
    [DataType.Double, 2.5, 2.5],
    [DataType.Float, 2.5, 2.5],
    [DataType.SByte, -3.4, -3],
    [DataType.Byte, 254.6, 255],
    [DataType.Int16, -100, -100],
    [DataType.UInt16, 0, 0],
    [DataType.Int32, 1e6, 1e6],
    [DataType.UInt32, 4e9, 4e9],
  ])('%s takes %s as %s', (dataType, value, expected) => {
    expect(variantFor(dataType, value).value).toBe(expected);
  });

  it.each([
    [DataType.Byte, -1, '-1 is out of range for Byte'],
    [DataType.Int64, 1, "can't write a number to a Int64 node"],
    [DataType.Null, 1, "can't write a number to a Null node"],
  ])('%s refuses %s', (dataType, value, message) => {
    expect(() => variantFor(dataType, value)).toThrow(message);
  });
});

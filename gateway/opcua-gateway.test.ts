import { createHmac } from 'node:crypto';
import { mkdtempSync, readFileSync } from 'node:fs';
import { createServer, type Server } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import WebSocket from 'ws';
import type { ServerMessage } from '../resources/js/twin/core/protocol.ts';
import type { OpcUaMapping } from '../resources/js/twin/core/types.ts';
import { standaloneServers, startGateway } from './gateway.ts';
import type { OpcUaServerConfig } from './opcua.ts';
import { ticketKey } from './tickets.ts';
import { startSimulator } from './opcua-simulator.ts';

/** The gateway with the control plane faked and a real OPC UA server. */

const TOKEN = 'mgw_test-token';
const sample = JSON.parse(readFileSync('public/samples/harbour-depot.json', 'utf8')) as Record<string, unknown>;
const northshore = JSON.parse(readFileSync('public/samples/northshore.json', 'utf8')) as Record<string, unknown>;

const cleanups: (() => Promise<unknown> | void)[] = [];
afterEach(async () => {
  while (cleanups.length) await cleanups.pop()!();
});

function controlPlane(opcua: OpcUaMapping[], servers: OpcUaServerConfig[], base = sample) {
  const state = { version: 1, opcua, servers, writes: false, checkIns: [] as (string | undefined)[], audit: [] as unknown[] };
  const server: Server = createServer((req, res) => {
    if (req.method === 'POST') {
      let body = '';
      req.on('data', (chunk: Buffer) => (body += chunk.toString()));
      req.on('end', () => {
        if (req.url === '/api/gateway/opcua-writes') state.audit.push(JSON.parse(body));
        res.writeHead(202, { 'content-type': 'application/json' }).end('{}');
      });
      return;
    }
    state.checkIns.push(req.headers['x-gateway-opcua'] as string | undefined);
    const etag = `"v${state.version}${state.writes ? 'w' : ''}"`;
    if (req.headers['if-none-match'] === etag) {
      res.writeHead(304, { etag }).end();
      return;
    }
    res.writeHead(200, { 'content-type': 'application/json', etag });
    res.end(JSON.stringify({
      twin: { id: 'twin-1' },
      version: state.version,
      definition: { ...base, opcua: state.opcua },
      mqtt: null,
      opcua: { servers: state.servers, writes: state.writes },
    }));
  });
  return { state, server };
}

const listening = (server: Server) =>
  new Promise<string>((done) => server.listen(0, '127.0.0.1', () => done(`http://127.0.0.1:${(server.address() as { port: number }).port}`)));

describe('OPC UA through the gateway', { timeout: 40_000 }, () => {
  it('measures mapped nodes from the project’s servers, reports them, and follows changes', async () => {
    const sim = await startSimulator({ port: 48_480 });
    cleanups.push(sim.stop);
    const [plc] = standaloneServers(JSON.stringify([{ name: 'Plant PLC', endpoint: sim.endpoint }]));
    const cp = controlPlane(
      [
        { server: 'Plant PLC', node: 'ns=1;s=Store.TempC', asset: 'store', metric: 'tempC', samplingMs: 100, ttlS: 600 },
        { server: 'Plant PLC', node: 'ns=1;s=Meter.EnergyWh', asset: 'plant', metric: 'powerKw', scale: 0.001, samplingMs: 100 },
        { server: 'Elsewhere', node: 'i=1', asset: 'store', metric: 'tempC' },
      ],
      [plc!],
    );
    const url = await listening(cp.server);
    cleanups.push(() => void cp.server.close());
    const gateway = await startGateway({
      controlPlane: { url, token: TOKEN, pollMs: 100, flushMs: 60_000 },
      opcua: { pkiDir: mkdtempSync(join(tmpdir(), 'meridian-gateway-pki-')) },
      port: 0,
    });
    cleanups.push(() => gateway.close());
    const measure = vi.spyOn(gateway.runner, 'measure');

    sim.set('Meter.EnergyWh', 52_000);
    await vi.waitFor(() => {
      expect(measure).toHaveBeenCalledWith('store.tempC', -18.5, 600);
      expect(measure).toHaveBeenCalledWith('plant.powerKw', 52, undefined);
    }, { timeout: 20_000 });
    await vi.waitFor(() => expect(cp.state.checkIns.at(-1)).toBe('{"Plant PLC":"connected"}'), { timeout: 5000 });
    const health = await (await fetch(`http://127.0.0.1:${gateway.port}/health`)).json();
    expect(health.opcua).toMatchObject({ servers: { 'Plant PLC': { status: 'connected', nodes: 2 } }, unmapped: 0 });
    expect(health.opcua.mapped).toBeGreaterThanOrEqual(2);

    // The project's server is removed: the gateway lets it go.
    cp.state.servers = [];
    cp.state.version = 2;
    await vi.waitFor(() => expect(gateway.version).toBe(2));
    const after = await (await fetch(`http://127.0.0.1:${gateway.port}/health`)).json();
    expect(after.opcua.servers).toEqual({});
  });

  it('reads standalone servers as JSON, anonymous and unsecured by default', () => {
    expect(standaloneServers('[{"name": "PLC", "endpoint": "opc.tcp://plc:4840"}, {"name": "Meter", "endpoint": "opc.tcp://m:4840", "securityMode": "sign", "securityPolicy": "Basic256Sha256", "username": "u", "password": "p"}]')).toEqual([
      { name: 'PLC', endpoint: 'opc.tcp://plc:4840', securityMode: 'none', securityPolicy: 'None', username: null, password: null },
      { name: 'Meter', endpoint: 'opc.tcp://m:4840', securityMode: 'sign', securityPolicy: 'Basic256Sha256', username: 'u', password: 'p' },
    ]);
  });
});

function ticket(operate: boolean) {
  const payload = Buffer.from(JSON.stringify({ twin: 'twin-1', user: 'operator-1', operate, exp: Math.floor(Date.now() / 1000) + 3600 })).toString('base64url');
  return `${payload}.${createHmac('sha256', ticketKey(TOKEN)).update(payload).digest('base64url')}`;
}

async function socketTo(port: number, operate = true) {
  const socket = new WebSocket(`ws://127.0.0.1:${port}/ws?ticket=${ticket(operate)}`);
  const messages: ServerMessage[] = [];
  socket.on('message', (data) => messages.push(JSON.parse((data as Buffer).toString()) as ServerMessage));
  await new Promise((open) => socket.once('open', open));
  cleanups.push(() => socket.close());
  return { socket, errors: () => messages.filter((m) => m.type === 'error').map((m) => (m as { message: string }).message) };
}

describe('writing setpoints through the gateway', { timeout: 40_000 }, () => {
  it('writes an operator’s setpoint only while the twin allows it, and records each write', async () => {
    const sim = await startSimulator({ port: 48_481 });
    cleanups.push(sim.stop);
    const [plc] = standaloneServers(JSON.stringify([{ name: 'Plant PLC', endpoint: sim.endpoint }]));
    // The jockey pump's start pressure, held by the PLC in bar.
    const cp = controlPlane(
      [{ server: 'Plant PLC', node: 'ns=1;s=Pump.StartBar', asset: 'jockey', metric: 'startKpa', scale: 100, samplingMs: 100, write: true }],
      [plc!],
      northshore,
    );
    const url = await listening(cp.server);
    cleanups.push(() => void cp.server.close());
    const gateway = await startGateway({
      controlPlane: { url, token: TOKEN, pollMs: 100, flushMs: 60_000 },
      opcua: { pkiDir: mkdtempSync(join(tmpdir(), 'meridian-gateway-pki-')) },
      port: 0,
    });
    cleanups.push(() => gateway.close());
    await vi.waitFor(async () => {
      const health = await (await fetch(`http://127.0.0.1:${gateway.port}/health`)).json();
      expect(health.opcua.servers['Plant PLC'].status).toBe('connected');
    }, { timeout: 20_000 });
    const operator = await socketTo(gateway.port);

    // Off by default: the model changes, the PLC doesn't.
    operator.socket.send(JSON.stringify({ type: 'command', command: { type: 'setpoint', asset: 'jockey', metric: 'startKpa', value: 600 } }));
    await vi.waitFor(() => expect(operator.errors()).toEqual(["Writing to OPC UA is off for this twin, so only the model's jockey startKpa changed"]));
    expect(sim.writes).toEqual([]);

    cp.state.writes = true;
    cp.state.version = 2;
    await vi.waitFor(() => expect(gateway.version).toBe(2));
    operator.socket.send(JSON.stringify({ type: 'command', command: { type: 'setpoint', asset: 'jockey', metric: 'startKpa', value: 9000 } }));
    await vi.waitFor(() => expect(sim.writes).toEqual([{ node: 'Pump.StartBar', value: 7.2 }]), { timeout: 10_000 });
    await vi.waitFor(() => expect(cp.state.audit).toEqual([
      {
        server: 'Plant PLC', node: 'ns=1;s=Pump.StartBar', asset: 'jockey', metric: 'startKpa',
        requested: 9000, setpoint: 720, value: 7.2, user: 'operator-1', succeeded: true, error: null, at: expect.any(Number),
      },
    ]));

    // A viewer can't, and a setpoint that isn't mapped for writing only moves the model.
    const viewer = await socketTo(gateway.port, false);
    viewer.socket.send(JSON.stringify({ type: 'command', command: { type: 'setpoint', asset: 'jockey', metric: 'startKpa', value: 560 } }));
    operator.socket.send(JSON.stringify({ type: 'command', command: { type: 'setpoint', asset: 'jockey', metric: 'stopKpa', value: 700 } }));
    await vi.waitFor(() => expect(viewer.errors()).toEqual(['This connection can watch but not operate']));
    await new Promise((done) => setTimeout(done, 500));
    expect(sim.writes).toHaveLength(1);

    const health = await (await fetch(`http://127.0.0.1:${gateway.port}/health`)).json();
    expect(health.opcua).toMatchObject({ written: 1, failedWrites: 0 });
  });

  it('tells the operator when a write fails, and records it as failed', async () => {
    const sim = await startSimulator({ port: 48_482 });
    cleanups.push(sim.stop);
    const [plc] = standaloneServers(JSON.stringify([{ name: 'Plant PLC', endpoint: sim.endpoint }]));
    const cp = controlPlane(
      [{ server: 'Plant PLC', node: 'ns=1;s=Store.Status', asset: 'jockey', metric: 'startKpa', samplingMs: 100, write: true }],
      [plc!],
      northshore,
    );
    cp.state.writes = true;
    const url = await listening(cp.server);
    cleanups.push(() => void cp.server.close());
    const gateway = await startGateway({
      controlPlane: { url, token: TOKEN, pollMs: 100, flushMs: 60_000 },
      opcua: { pkiDir: mkdtempSync(join(tmpdir(), 'meridian-gateway-pki-')) },
      port: 0,
    });
    cleanups.push(() => gateway.close());
    await vi.waitFor(async () => {
      const health = await (await fetch(`http://127.0.0.1:${gateway.port}/health`)).json();
      expect(health.opcua.servers['Plant PLC'].status).toBe('connected');
    }, { timeout: 20_000 });
    const operator = await socketTo(gateway.port);

    operator.socket.send(JSON.stringify({ type: 'command', command: { type: 'setpoint', asset: 'jockey', metric: 'startKpa', value: 600 } }));

    await vi.waitFor(() => expect(operator.errors()).toEqual(["Couldn't write ns=1;s=Store.Status on Plant PLC: can't write a number to a String node"]), { timeout: 10_000 });
    await vi.waitFor(() => expect(cp.state.audit).toMatchObject([{ succeeded: false, error: "can't write a number to a String node", user: 'operator-1' }]));
  });
});

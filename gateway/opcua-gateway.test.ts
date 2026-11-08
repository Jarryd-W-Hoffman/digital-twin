import { mkdtempSync, readFileSync } from 'node:fs';
import { createServer, type Server } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { OpcUaMapping } from '../resources/js/twin/core/types.ts';
import { standaloneServers, startGateway } from './gateway.ts';
import type { OpcUaServerConfig } from './opcua.ts';
import { startSimulator } from './opcua-simulator.ts';

/** The gateway with the control plane faked and a real OPC UA server. */

const TOKEN = 'mgw_test-token';
const sample = JSON.parse(readFileSync('public/samples/harbour-depot.json', 'utf8')) as Record<string, unknown>;

const cleanups: (() => Promise<unknown> | void)[] = [];
afterEach(async () => {
  while (cleanups.length) await cleanups.pop()!();
});

function controlPlane(opcua: OpcUaMapping[], servers: OpcUaServerConfig[]) {
  const state = { version: 1, opcua, servers, checkIns: [] as (string | undefined)[] };
  const server: Server = createServer((req, res) => {
    if (req.method === 'POST') {
      req.resume();
      req.on('end', () => res.writeHead(202, { 'content-type': 'application/json' }).end('{}'));
      return;
    }
    state.checkIns.push(req.headers['x-gateway-opcua'] as string | undefined);
    const etag = `"v${state.version}"`;
    if (req.headers['if-none-match'] === etag) {
      res.writeHead(304, { etag }).end();
      return;
    }
    res.writeHead(200, { 'content-type': 'application/json', etag });
    res.end(JSON.stringify({
      twin: { id: 'twin-1' },
      version: state.version,
      definition: { ...sample, opcua: state.opcua },
      mqtt: null,
      opcua: { servers: state.servers },
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

import { EventEmitter } from 'node:events';
import { readFileSync } from 'node:fs';
import { createServer, type Server } from 'node:http';
import type { IClientOptions } from 'mqtt';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { TelemetryMapping } from '../resources/js/twin/core/types.ts';
import { startGateway, type MqttClientLike } from './gateway.ts';
import { MAX_BUFFERED, ReadingLog } from './readings.ts';

const TOKEN = 'mgw_test-token';
const PREFIX = 'p/harbour-x1y2/';
const sample = JSON.parse(readFileSync('public/samples/harbour-depot.json', 'utf8')) as Record<string, unknown>;

/** Records what the gateway subscribes to, and lets a test publish to it. */
class FakeBroker extends EventEmitter implements MqttClientLike {
  connected = false;
  subscribed: string[] = [];
  ended = false;
  subscribe(topics: string[]) {
    this.subscribed.push(...topics);
  }
  unsubscribe(topics: string[]) {
    this.subscribed = this.subscribed.filter((t) => !topics.includes(t));
  }
  end() {
    this.ended = true;
  }
  up() {
    this.connected = true;
    this.emit('connect');
  }
  publish(topic: string, payload: string) {
    this.emit('message', topic, Buffer.from(payload));
  }
}

/** The control plane: serves a definition with `telemetry`, takes readings. */
function controlPlane(telemetry: TelemetryMapping[]) {
  const state = { version: 1, telemetry, batches: [] as unknown[][], status: 202, checkIns: [] as (string | undefined)[] };
  const server: Server = createServer((req, res) => {
    if (req.headers.authorization !== `Bearer ${TOKEN}`) {
      res.writeHead(401).end();
      return;
    }
    if (req.method === 'POST' && req.url === '/api/gateway/readings') {
      let body = '';
      req.on('data', (c: Buffer) => (body += c.toString()));
      req.on('end', () => {
        if (state.status === 202) state.batches.push((JSON.parse(body) as { readings: unknown[] }).readings);
        res.writeHead(state.status, { 'content-type': 'application/json' }).end('{}');
      });
      return;
    }
    state.checkIns.push(req.headers['x-gateway-mqtt'] as string | undefined);
    const etag = `"v${state.version}"`;
    if (req.headers['if-none-match'] === etag) {
      res.writeHead(304, { etag }).end();
      return;
    }
    res.writeHead(200, { 'content-type': 'application/json', etag });
    res.end(
      JSON.stringify({
        twin: { id: 'twin-1' },
        version: state.version,
        definition: { ...sample, telemetry: state.telemetry },
        mqtt: { username: 'twin-twin-1', topicPrefix: PREFIX },
      }),
    );
  });
  return { state, server };
}

const listening = (server: Server) =>
  new Promise<string>((done) => server.listen(0, '127.0.0.1', () => done(`http://127.0.0.1:${(server.address() as { port: number }).port}`)));

const cleanups: (() => Promise<void> | void)[] = [];
afterEach(async () => {
  for (const c of cleanups.splice(0)) await c();
});

async function gatewayWith(telemetry: TelemetryMapping[], login: { username?: string; password?: string } = {}) {
  const cp = controlPlane(telemetry);
  const url = await listening(cp.server);
  const broker = new FakeBroker();
  const connect = vi.fn((_url: string, _options: IClientOptions) => broker);
  const gateway = await startGateway({
    controlPlane: { url, token: TOKEN, pollMs: 50, flushMs: 60_000 },
    mqtt: { url: 'mqtt://broker', connect, ...login },
    port: 0,
  });
  cleanups.push(() => gateway.close(), () => void cp.server.close());
  return { cp, broker, gateway, url, connect };
}

describe('MQTT bridge', () => {
  it('subscribes once connected, and measures and logs mapped messages', async () => {
    const { broker, gateway, cp } = await gatewayWith([
      { topic: 'harbour/store/temp', asset: 'store', metric: 'tempC', ttlS: 900 },
      { topic: 'harbour/+/power', asset: 'plant', metric: 'powerKw', path: 'w', scale: 0.001 },
    ]);
    expect(broker.subscribed).toEqual([]);
    broker.up();
    expect(broker.subscribed).toEqual([`${PREFIX}harbour/store/temp`, `${PREFIX}harbour/+/power`]);

    const measure = vi.spyOn(gateway.runner, 'measure');
    broker.publish(`${PREFIX}harbour/store/temp`, '-11.5');
    broker.publish(`${PREFIX}harbour/plant/power`, '{"w": 52000}');
    broker.publish(`${PREFIX}harbour/elsewhere`, '1');

    expect(measure.mock.calls).toEqual([
      ['store.tempC', -11.5, 900],
      ['plant.powerKw', 52, undefined],
    ]);
    const health = await (await fetch(`http://127.0.0.1:${gateway.port}/health`)).json();
    expect(health.mqtt).toEqual({
      connected: true,
      topics: [`${PREFIX}harbour/store/temp`, `${PREFIX}harbour/+/power`],
      received: 3,
      mapped: 2,
      unmapped: 1,
    });

    await gateway.log!.flush();
    expect(cp.state.batches).toEqual([
      [
        { asset: 'store', metric: 'tempC', value: -11.5, at: expect.any(Number) },
        { asset: 'plant', metric: 'powerKw', value: 52, at: expect.any(Number) },
      ],
    ]);
  });

  it('follows new mappings when the twin changes', async () => {
    const { broker, cp, gateway } = await gatewayWith([{ topic: 'a', asset: 'store', metric: 'tempC' }]);
    broker.up();

    cp.state.telemetry = [{ topic: 'b', asset: 'store', metric: 'tempC' }];
    cp.state.version = 2;
    await vi.waitFor(() => expect(gateway.version).toBe(2));

    expect(broker.subscribed).toEqual([`${PREFIX}b`]);
  });

  it("logs in as the twin with its token, and reports the broker connection on check-in", async () => {
    const { broker, cp, connect } = await gatewayWith([]);

    expect(connect.mock.calls[0][1]).toMatchObject({ username: 'twin-twin-1', password: TOKEN });
    await vi.waitFor(() => expect(cp.state.checkIns).toContain('disconnected'));
    broker.up();
    await vi.waitFor(() => expect(cp.state.checkIns.at(-1)).toBe('connected'));
  });

  it('logs in with MQTT_USERNAME and MQTT_PASSWORD when given', async () => {
    const { connect } = await gatewayWith([], { username: 'local', password: 'pw' });

    expect(connect.mock.calls[0][1]).toMatchObject({ username: 'local', password: 'pw' });
  });

  it('skips mappings onto metrics the asset lacks, and ends the client on close', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const { broker, gateway } = await gatewayWith([{ topic: 'a', asset: 'store', metric: 'rpm' }]);
    broker.up();

    expect(broker.subscribed).toEqual([]);
    expect(warn).toHaveBeenCalledWith('Gateway: Telemetry a: store has no metric rpm');
    await gateway.close();
    expect(broker.ended).toBe(true);
  });

  it('logs readings posted to /ingest too', async () => {
    const { gateway, cp } = await gatewayWith([]);

    await fetch(`http://127.0.0.1:${gateway.port}/ingest`, {
      method: 'POST',
      headers: { authorization: `Bearer ${TOKEN}`, 'content-type': 'application/json' },
      body: JSON.stringify({ asset: 'store', metric: 'tempC', value: -20 }),
    });
    await gateway.log!.flush();

    expect(cp.state.batches).toEqual([[{ asset: 'store', metric: 'tempC', value: -20, at: expect.any(Number) }]]);
  });
});

describe('reading log', () => {
  it('keeps readings the control plane refused for the next flush', async () => {
    const cp = controlPlane([]);
    const url = await listening(cp.server);
    cleanups.push(() => void cp.server.close());
    const log = new ReadingLog({ url, token: TOKEN });

    log.add([{ asset: 'store', metric: 'tempC', value: 1 }], 1000);
    cp.state.status = 503;
    await expect(log.flush()).rejects.toThrow('Control plane answered 503 for 1 readings');
    expect(log.pending).toBe(1);

    cp.state.status = 202;
    await log.flush();
    expect(log.pending).toBe(0);
    expect(log.sent).toBe(1);
    expect(cp.state.batches).toEqual([[{ asset: 'store', metric: 'tempC', value: 1, at: 1000 }]]);
  });

  it('sends big backlogs in batches and drops the oldest past its limit', async () => {
    const cp = controlPlane([]);
    const url = await listening(cp.server);
    cleanups.push(() => void cp.server.close());
    const log = new ReadingLog({ url, token: TOKEN });

    log.add(Array.from({ length: MAX_BUFFERED + 5 }, (_, i) => ({ asset: 'store', metric: 'tempC', value: i })));
    expect(log.dropped).toBe(5);
    await log.flush();

    expect(cp.state.batches.map((b) => b.length)).toEqual(Array(10).fill(1000));
    expect((cp.state.batches[0][0] as { value: number }).value).toBe(5);
  });
});

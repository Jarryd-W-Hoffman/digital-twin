import { readFileSync } from 'node:fs';
import { createServer, type Server } from 'node:http';
import { createHmac } from 'node:crypto';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import WebSocket from 'ws';
import type { ServerMessage } from '../resources/js/twin/core/protocol.ts';
import { fetchDefinition, startGateway } from './gateway.ts';
import { ticketKey } from './tickets.ts';

const TOKEN = 'mgw_test-token';
const TWIN = 'twin-1';
const sample = JSON.parse(readFileSync('public/samples/harbour-depot.json', 'utf8')) as { name: string };

/** A stand-in for the Laravel app's GET /api/gateway/definition. */
function controlPlane() {
  const state = { version: 1, name: sample.name, seenUrls: [] as (string | undefined)[], fail: false };
  const server: Server = createServer((req, res) => {
    state.seenUrls.push(req.headers['x-gateway-url'] as string | undefined);
    if (state.fail) {
      res.writeHead(500).end();
      return;
    }
    if (req.url !== '/api/gateway/definition' || req.headers.authorization !== `Bearer ${TOKEN}`) {
      res.writeHead(401, { 'content-type': 'application/json' }).end('{"message":"no"}');
      return;
    }
    const etag = `"v${state.version}"`;
    if (req.headers['if-none-match'] === etag) {
      res.writeHead(304, { etag }).end();
      return;
    }
    res.writeHead(200, { 'content-type': 'application/json', etag });
    res.end(JSON.stringify({ twin: { id: TWIN }, version: state.version, definition: { ...sample, name: state.name } }));
  });
  return { state, server };
}

function ticket(operate: boolean, exp = Math.floor(Date.now() / 1000) + 3600, twin = TWIN) {
  const payload = Buffer.from(JSON.stringify({ twin, user: 'u1', operate, exp })).toString('base64url');
  return `${payload}.${createHmac('sha256', ticketKey(TOKEN)).update(payload).digest('base64url')}`;
}

const nextMessage = (socket: WebSocket, type: ServerMessage['type']) =>
  new Promise<ServerMessage>((done) => {
    const listen = (data: WebSocket.RawData) => {
      const message = JSON.parse((data as Buffer).toString()) as ServerMessage;
      if (message.type === type) {
        socket.off('message', listen);
        done(message);
      }
    };
    socket.on('message', listen);
  });

const refused = (url: string) =>
  new Promise<number | undefined>((done) => {
    const socket = new WebSocket(url);
    socket.on('unexpected-response', (_req, res) => done(res.statusCode));
    socket.on('open', () => {
      socket.close();
      done(undefined);
    });
  });

describe('gateway with a control plane', () => {
  const cp = controlPlane();
  let url: string;
  let gw: Awaited<ReturnType<typeof startGateway>>;
  let ws: string;

  beforeAll(async () => {
    await new Promise<void>((done) => cp.server.listen(0, '127.0.0.1', done));
    const address = cp.server.address();
    url = `http://127.0.0.1:${typeof address === 'object' && address ? address.port : 0}`;
    gw = await startGateway({
      port: 0,
      controlPlane: { url: `${url}/`, token: TOKEN, publicUrl: 'ws://gw.test/ws', pollMs: 50 },
    });
    ws = `ws://127.0.0.1:${gw.port}/ws`;
  });
  afterAll(async () => {
    await gw.close();
    cp.server.close();
  });

  it('loads the latest version and reports where browsers reach it', async () => {
    expect(gw.version).toBe(1);
    expect(cp.state.seenUrls[0]).toBe('ws://gw.test/ws');
    const health = (await (await fetch(`http://127.0.0.1:${gw.port}/health`)).json()) as { version: number };
    expect(health.version).toBe(1);
  });

  it('lets browsers in only with a ticket signed for this twin', async () => {
    expect(await refused(ws)).toBe(401);
    expect(await refused(`${ws}?ticket=nonsense`)).toBe(401);
    expect(await refused(`${ws}?ticket=${ticket(true, Math.floor(Date.now() / 1000) - 1)}`)).toBe(401);
    expect(await refused(`${ws}?ticket=${ticket(true, undefined, 'other-twin')}`)).toBe(401);
    expect(await refused(`${ws}?ticket=${ticket(true)}`)).toBeUndefined();
  });

  it('lets a viewer watch but not operate', async () => {
    const socket = new WebSocket(`${ws}?ticket=${ticket(false)}`);
    await nextMessage(socket, 'hello');
    socket.send(JSON.stringify({ type: 'command', command: { type: 'clear-faults' } }));
    const error = await nextMessage(socket, 'error');
    expect(error).toMatchObject({ message: 'This connection can watch but not operate' });
    socket.close();
  });

  it('switches to a new version and greets open browsers again', async () => {
    const socket = new WebSocket(`${ws}?ticket=${ticket(true)}`);
    await nextMessage(socket, 'hello');
    cp.state.version = 2;
    cp.state.name = 'Harbour Depot (moved)';
    const hello = await nextMessage(socket, 'hello');
    expect(hello.type === 'hello' && hello.definition.name).toBe('Harbour Depot (moved)');
    expect(gw.version).toBe(2);
    socket.close();
  });

  it('keeps running when the control plane is down', async () => {
    cp.state.fail = true;
    await new Promise((done) => setTimeout(done, 200));
    expect(gw.version).toBe(2);
    cp.state.fail = false;
  });

  it('uses the gateway token for readings', async () => {
    const post = (token: string) =>
      fetch(`http://127.0.0.1:${gw.port}/ingest`, {
        method: 'POST',
        headers: { authorization: `Bearer ${token}` },
        body: JSON.stringify({ asset: 'store', metric: 'tempC', value: 3 }),
      });
    expect((await post('wrong')).status).toBe(401);
    expect((await post(TOKEN)).status).toBe(202);
  });

  it('fails to start with a token the control plane refuses', async () => {
    await expect(fetchDefinition({ url, token: 'mgw_wrong' })).rejects.toThrow('Control plane answered 401');
  });
});

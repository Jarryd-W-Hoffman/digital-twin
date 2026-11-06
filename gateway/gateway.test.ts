import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import WebSocket from 'ws';
import type { ServerMessage } from '../resources/js/twin/core/protocol.ts';
import { isAllowedOrigin, startGateway } from './gateway.ts';

describe('gateway', () => {
  let gw: Awaited<ReturnType<typeof startGateway>>;
  let base: string;

  beforeAll(async () => {
    gw = await startGateway({ port: 0, token: 'secret', now: Date.UTC(2026, 10, 5) });
    base = `http://127.0.0.1:${gw.port}`;
  });
  afterAll(() => gw.close());

  const ingest = (body: unknown, token = 'secret') =>
    fetch(`${base}/ingest`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}` },
      body: JSON.stringify(body),
    });

  const nextMessage = (socket: WebSocket, type: ServerMessage['type']) =>
    new Promise<ServerMessage>((done) => {
      const listen = (data: WebSocket.RawData) => {
        const message = JSON.parse(String(data)) as ServerMessage;
        if (message.type === type) {
          socket.off('message', listen);
          done(message);
        }
      };
      socket.on('message', listen);
    });

  it('reports health', async () => {
    const res = await fetch(`${base}/health`);
    expect(await res.json()).toMatchObject({ status: 'ok', twin: 'northshore' });
  });

  it('greets a client with the definition and history, then streams ticks', async () => {
    const socket = new WebSocket(`ws://127.0.0.1:${gw.port}/ws`);
    const hello = await nextMessage(socket, 'hello');
    if (hello.type !== 'hello') throw new Error('expected hello');
    expect(hello.definition.id).toBe('northshore');
    expect(hello.history.length).toBeGreaterThan(400);
    const tick = await nextMessage(socket, 'tick');
    if (tick.type !== 'tick') throw new Error('expected tick');
    expect(tick.live.values['freezer.tempC']).toBeLessThan(-15);
    socket.close();
  });

  it('applies a reading, reports it as measured, and the rest follows', async () => {
    const res = await ingest({ asset: 'freezer', metric: 'tempC', value: -9.5, ttlS: 600 });
    expect(res.status).toBe(202);
    expect(gw.runner.state.values['freezer.tempC']).toBe(-9.5);
    const socket = new WebSocket(`ws://127.0.0.1:${gw.port}/ws`);
    const hello = await nextMessage(socket, 'hello');
    if (hello.type !== 'hello') throw new Error('expected hello');
    expect(hello.measured).toContain('freezer.tempC');
    const tick = await nextMessage(socket, 'tick');
    if (tick.type !== 'tick') throw new Error('expected tick');
    expect(tick.live.values['freezer.tempC']).toBe(-9.5);
    // The chillers saw the reading: they respond to the measured temperature.
    expect(tick.live.values['ch-1.coolingKw']).toBeGreaterThan(100);
    socket.close();
  });

  it('runs commands from a client', async () => {
    const socket = new WebSocket(`ws://127.0.0.1:${gw.port}/ws`);
    await nextMessage(socket, 'hello');
    socket.send(JSON.stringify({ type: 'command', command: { type: 'setpoint', asset: 'chillroom', value: 2 } }));
    await nextMessage(socket, 'tick');
    expect(gw.runner.state.values['chillroom.setpointC']).toBe(2);
    socket.send(JSON.stringify({ type: 'command', command: { type: 'setpoint', asset: 'nope', value: 2 } }));
    const error = await nextMessage(socket, 'error');
    expect(error).toMatchObject({ message: 'nope is not a cold store' });
    socket.send(JSON.stringify({ type: 'speed', speed: 1e9 }));
    expect((await nextMessage(socket, 'error')).type).toBe('error');
    socket.close();
  });

  it('rejects bad readings as a batch, with reasons', async () => {
    const res = await ingest([
      { asset: 'freezer', metric: 'tempC', value: -20 },
      { asset: 'ghost', metric: 'tempC', value: 1 },
      { asset: 'freezer', metric: 'x', value: 1 },
      { asset: 'freezer', metric: 'tempC', value: 'hot' },
      { asset: 'truck-1', metric: 'x', value: 1 },
      null,
    ]);
    expect(res.status).toBe(422);
    const { errors } = (await res.json()) as { errors: string[] };
    expect(errors).toHaveLength(5);
  });

  it('requires the token, and rejects malformed and oversized bodies', async () => {
    expect((await ingest({}, 'wrong')).status).toBe(401);
    const bad = await fetch(`${base}/ingest`, { method: 'POST', headers: { authorization: 'Bearer secret' }, body: '{' });
    expect(bad.status).toBe(400);
    const big = await fetch(`${base}/ingest`, {
      method: 'POST',
      headers: { authorization: 'Bearer secret' },
      body: 'x'.repeat(300 * 1024),
    }).catch(() => null);
    expect(big === null || big.status === 400).toBe(true);
    expect((await fetch(`${base}/nope`)).status).toBe(404);
  });

  it('refuses sockets from a foreign browser origin', async () => {
    const socket = new WebSocket(`ws://127.0.0.1:${gw.port}/ws`, { origin: 'https://evil.example' });
    const outcome = await new Promise((done) => {
      socket.on('open', () => done('open'));
      socket.on('error', () => done('refused'));
    });
    expect(outcome).toBe('refused');
  });

  it('classifies origins', () => {
    expect(isAllowedOrigin(undefined)).toBe(true);
    expect(isAllowedOrigin('http://localhost:5173')).toBe(true);
    expect(isAllowedOrigin('http://127.0.0.1:5173')).toBe(true);
    expect(isAllowedOrigin('https://localhost.evil.example')).toBe(false);
    expect(isAllowedOrigin('https://ops.example', ['https://ops.example'])).toBe(true);
    expect(isAllowedOrigin('not a url')).toBe(false);
  });
});

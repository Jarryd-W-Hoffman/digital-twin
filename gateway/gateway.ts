import { readFileSync } from 'node:fs';
import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import { resolve } from 'node:path';
import { WebSocketServer, type WebSocket } from 'ws';
import { History } from '../resources/js/twin/core/history.ts';
import { parseReadings } from '../resources/js/twin/core/ingest.ts';
import { siteTime, SimRunner } from '../resources/js/twin/core/runner.ts';
import type { ClientMessage, ServerMessage } from '../resources/js/twin/core/protocol.ts';
import { parseDefinition } from '../resources/js/twin/core/validate.ts';

/**
 * The twin as a service: runs the model, streams it to any number of browsers
 * over `/ws`, and takes real sensor readings at `POST /ingest`. A reading pins
 * its metric, and the model integrates from it, so the twin tracks reality.
 *
 * Binds to localhost by default. To expose it, set GATEWAY_HOST, GATEWAY_TOKEN
 * and GATEWAY_ORIGINS.
 */

const MAX_BODY_BYTES = 256 * 1024;
const TICK_MS = 500;
const PREROLL_S = 8 * 3600;
const HISTORY_FRAMES = 1440;

export interface GatewayOptions {
  definitionPath?: string;
  port?: number;
  host?: string;
  token?: string;
  /** Extra browser origins allowed to open the socket; localhost always may. */
  origins?: string[];
  now?: number;
}

export function isAllowedOrigin(origin: string | undefined, extra: string[] = []): boolean {
  // Non-browser clients send no Origin; browsers always do, and cannot forge it.
  if (!origin) return true;
  if (extra.includes(origin)) return true;
  try {
    const { hostname } = new URL(origin);
    return hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '[::1]';
  } catch {
    return false;
  }
}

export function startGateway(options: GatewayOptions = {}) {
  const path = options.definitionPath ?? resolve(import.meta.dirname, '../public/samples/northshore.json');
  const definition = parseDefinition(JSON.parse(readFileSync(path, 'utf8')));
  const now = options.now ?? Date.now();
  const start = siteTime(now, definition.utcOffsetHours, 14) - PREROLL_S * 1000;
  const runner = new SimRunner(definition, start);
  const history = new History(HISTORY_FRAMES);
  for (const f of runner.preroll(PREROLL_S)) history.push(f);

  const sockets = new Set<WebSocket>();
  const send = (socket: WebSocket, message: ServerMessage) => socket.send(JSON.stringify(message));

  const respond = (res: ServerResponse, status: number, body: unknown) => {
    res.writeHead(status, { 'content-type': 'application/json' });
    res.end(JSON.stringify(body));
  };

  const readBody = (req: IncomingMessage) =>
    new Promise<string>((done, fail) => {
      const chunks: Buffer[] = [];
      let size = 0;
      req.on('data', (chunk: Buffer) => {
        size += chunk.length;
        if (size > MAX_BODY_BYTES) {
          fail(new Error('too large'));
          req.destroy();
          return;
        }
        chunks.push(chunk);
      });
      req.on('end', () => done(Buffer.concat(chunks).toString('utf8')));
      req.on('error', fail);
    });

  const server = createServer(async (req, res) => {
    const url = new URL(req.url ?? '/', 'http://gateway');
    if (req.method === 'GET' && url.pathname === '/health') {
      respond(res, 200, { status: 'ok', twin: definition.id, t: runner.state.t, clients: sockets.size });
      return;
    }
    if (req.method === 'POST' && url.pathname === '/ingest') {
      if (options.token && req.headers.authorization !== `Bearer ${options.token}`) {
        respond(res, 401, { errors: ['Missing or wrong bearer token'] });
        return;
      }
      let body: unknown;
      try {
        body = JSON.parse(await readBody(req));
      } catch {
        respond(res, 400, { errors: ['Body must be JSON, at most 256 KiB'] });
        return;
      }
      const parsed = parseReadings(definition, body);
      if (!parsed.ok) {
        respond(res, 422, { errors: parsed.errors });
        return;
      }
      for (const r of parsed.readings) runner.measure(`${r.asset}.${r.metric}`, r.value, r.ttlS);
      respond(res, 202, { accepted: parsed.readings.length });
      return;
    }
    respond(res, 404, { errors: ['Not found'] });
  });

  const wss = new WebSocketServer({
    server,
    path: '/ws',
    maxPayload: 64 * 1024,
    verifyClient: ({ origin }: { origin?: string }) => isAllowedOrigin(origin, options.origins),
  });
  wss.on('connection', (socket) => {
    sockets.add(socket);
    const frames = [];
    for (let i = 0; i < history.length; i++) frames.push(history.at(i)!);
    const tick = runner.tick(0);
    send(socket, { type: 'hello', definition, speed: runner.speed, ...tick, history: frames });
    socket.on('close', () => sockets.delete(socket));
    socket.on('message', (data) => {
      try {
        const message = JSON.parse(String(data)) as ClientMessage;
        if (message.type === 'command') runner.command(message.command);
        else if (message.type === 'speed' && [0, 1, 60, 600].includes(message.speed)) runner.speed = message.speed;
        else throw new Error('Unknown message');
      } catch (error) {
        send(socket, { type: 'error', message: error instanceof Error ? error.message : 'Bad message' });
      }
    });
  });

  let last = performance.now();
  const timer = setInterval(() => {
    const t = performance.now();
    const tick = runner.tick((t - last) / 1000);
    last = t;
    for (const f of tick.history) history.push(f);
    if (sockets.size === 0) return;
    const payload = JSON.stringify({ type: 'tick', ...tick, speed: runner.speed } satisfies ServerMessage);
    for (const s of sockets) s.send(payload);
  }, TICK_MS);

  const port = options.port ?? 8787;
  const host = options.host ?? '127.0.0.1';
  return new Promise<{ port: number; runner: SimRunner; close: () => Promise<void> }>((done) => {
    server.listen(port, host, () => {
      const address = server.address();
      done({
        port: typeof address === 'object' && address ? address.port : port,
        runner,
        close: () =>
          new Promise((closed) => {
            clearInterval(timer);
            for (const s of sockets) s.terminate();
            wss.close();
            server.close(() => closed());
          }),
      });
    });
  });
}

if (process.argv[1] === import.meta.filename) {
  const { port } = await startGateway({
    definitionPath: process.argv[2],
    port: Number(process.env.GATEWAY_PORT ?? 8787),
    host: process.env.GATEWAY_HOST,
    token: process.env.GATEWAY_TOKEN,
    origins: process.env.GATEWAY_ORIGINS?.split(',').filter(Boolean),
  });
  console.log(`Meridian gateway on http://127.0.0.1:${port} (socket at /ws, readings at POST /ingest)`);
}

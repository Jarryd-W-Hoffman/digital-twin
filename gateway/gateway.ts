import { readFileSync } from 'node:fs';
import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import { resolve } from 'node:path';
import { connect as connectMqtt, type IClientOptions } from 'mqtt';
import { WebSocketServer, type WebSocket } from 'ws';
import type { TwinDefinition } from '../resources/js/twin/core/types.ts';
import { History } from '../resources/js/twin/core/history.ts';
import { parseReadings } from '../resources/js/twin/core/ingest.ts';
import { siteTime, SimRunner } from '../resources/js/twin/core/runner.ts';
import type { ClientMessage, Reading, ServerMessage } from '../resources/js/twin/core/protocol.ts';
import { compileTelemetry, type Telemetry } from '../resources/js/twin/core/telemetry.ts';
import { parseDefinition } from '../resources/js/twin/core/validate.ts';
import { ReadingLog } from './readings.ts';
import { ticketKey, verifyTicket, type TicketAccess } from './tickets.ts';

/**
 * The twin as a service: runs the model, streams it to any number of browsers
 * over `/ws`, and takes real sensor readings at `POST /ingest`. A reading pins
 * its metric, and the model integrates from it, so the twin tracks reality.
 *
 * The twin comes from a JSON file, or from the control plane: with a gateway
 * token it fetches the twin's latest version, polls for new ones and swaps
 * them in, and lets browsers in only with a ticket the control plane signed.
 * Every reading is also sent on to the control plane's history.
 *
 * With an MQTT broker, it subscribes to the topics in the twin's `telemetry`
 * mappings and takes each message as a reading, as if posted to /ingest.
 *
 * Binds to localhost by default. To expose it, set GATEWAY_HOST, GATEWAY_TOKEN
 * (or use a control plane token) and GATEWAY_ORIGINS.
 */

const MAX_BODY_BYTES = 256 * 1024;
const TICK_MS = 500;
const PREROLL_S = 8 * 3600;
const HISTORY_FRAMES = 1440;

export interface ControlPlaneOptions {
  /** The Meridian app, e.g. http://localhost:8000. */
  url: string;
  /** The twin's gateway token, issued in its Layout panel. */
  token: string;
  /** Where browsers reach this gateway (ws:// or wss://), reported on every poll. */
  publicUrl?: string;
  pollMs?: number;
  /** How often readings are sent on to the control plane's history. */
  flushMs?: number;
}

/** The parts of an MQTT client the gateway uses. */
export interface MqttClientLike {
  on(event: 'connect', listener: () => void): unknown;
  on(event: 'message', listener: (topic: string, payload: Buffer) => void): unknown;
  on(event: 'error', listener: (error: Error) => void): unknown;
  subscribe(topics: string[]): unknown;
  unsubscribe(topics: string[]): unknown;
  end(force?: boolean): unknown;
  readonly connected: boolean;
}

export interface MqttOptions {
  /** The broker, e.g. mqtt://localhost:1883. */
  url: string;
  username?: string;
  password?: string;
  /** Swaps in another client, for tests. */
  connect?: (url: string, options: IClientOptions) => MqttClientLike;
}

export interface GatewayOptions {
  definitionPath?: string;
  controlPlane?: ControlPlaneOptions;
  mqtt?: MqttOptions;
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

interface Fetched {
  twinId: string;
  version: number;
  definition: TwinDefinition;
  etag: string | null;
}

/** Fetches the twin's latest version; null when it hasn't changed since `etag`. */
export async function fetchDefinition(cp: ControlPlaneOptions, etag: string | null = null): Promise<Fetched | null> {
  const headers: Record<string, string> = { authorization: `Bearer ${cp.token}`, accept: 'application/json' };
  if (cp.publicUrl) headers['x-gateway-url'] = cp.publicUrl;
  if (etag) headers['if-none-match'] = etag;
  // A hung request would otherwise stall every later poll, and the check-ins with them.
  const res = await fetch(`${cp.url.replace(/\/+$/, '')}/api/gateway/definition`, {
    headers,
    signal: AbortSignal.timeout(10_000),
  });
  if (res.status === 304) return null;
  if (!res.ok) throw new Error(`Control plane answered ${res.status} for the twin definition`);
  const body = (await res.json()) as { twin: { id: string }; version: number; definition: unknown };
  return {
    twinId: body.twin.id,
    version: body.version,
    definition: parseDefinition(body.definition),
    etag: res.headers.get('etag'),
  };
}

export async function startGateway(options: GatewayOptions = {}) {
  const cp = options.controlPlane;
  let fetched: Fetched | null = null;
  let definition: TwinDefinition;
  if (cp) {
    fetched = await fetchDefinition(cp);
    definition = fetched!.definition;
  } else {
    const path = options.definitionPath ?? resolve(import.meta.dirname, '../public/samples/northshore.json');
    definition = parseDefinition(JSON.parse(readFileSync(path, 'utf8')));
  }
  const key = cp ? ticketKey(cp.token) : null;
  const ingestToken = options.token ?? cp?.token;

  const log = cp ? new ReadingLog(cp) : null;
  /** Feeds the model and the history alike, from /ingest or MQTT. */
  const measure = (readings: Reading[]) => {
    for (const r of readings) runner.measure(`${r.asset}.${r.metric}`, r.value, r.ttlS);
    log?.add(readings);
  };

  let runner!: SimRunner;
  let history!: History;
  let telemetry: Telemetry = compileTelemetry({ ...definition, telemetry: [] });
  const boot = (def: TwinDefinition, now: number) => {
    definition = def;
    const compiled = compileTelemetry(def);
    for (const problem of compiled.skipped) console.warn(`Gateway: ${problem}`);
    const before = telemetry.topics;
    telemetry = compiled;
    resubscribe(before);
    const start = siteTime(now, def.utcOffsetHours, 14) - PREROLL_S * 1000;
    runner = new SimRunner(def, start);
    history = new History(HISTORY_FRAMES);
    for (const f of runner.preroll(PREROLL_S)) history.push(f);
  };
  const mqttStats = { received: 0, mapped: 0, unmapped: 0 };
  const client = options.mqtt
    ? (options.mqtt.connect ?? (connectMqtt as unknown as NonNullable<MqttOptions['connect']>))(options.mqtt.url, {
        username: options.mqtt.username,
        password: options.mqtt.password,
        reconnectPeriod: 2000,
        connectTimeout: 10_000,
      })
    : null;
  /** Follows the twin's mappings: subscribes to new filters, drops old ones. */
  function resubscribe(before: string[]) {
    if (!client) return;
    const now = telemetry.topics;
    const gone = before.filter((t) => !now.includes(t));
    const added = now.filter((t) => !before.includes(t));
    if (gone.length) client.unsubscribe(gone);
    if (added.length && client.connected) client.subscribe(added);
  }
  client?.on('connect', () => {
    if (telemetry.topics.length) client.subscribe(telemetry.topics);
  });
  client?.on('error', (error) => console.warn(`Gateway: MQTT ${error.message}`));
  client?.on('message', (topic, payload) => {
    mqttStats.received++;
    const { readings, problems } = telemetry.read(topic, payload.toString('utf8'));
    for (const problem of problems) console.warn(`Gateway: ${problem}`);
    if (readings.length === 0) {
      mqttStats.unmapped++;
      return;
    }
    mqttStats.mapped++;
    measure(readings);
  });

  boot(definition, options.now ?? Date.now());

  const sockets = new Map<WebSocket, TicketAccess | null>();
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
      respond(res, 200, {
        status: 'ok',
        twin: definition.id,
        version: fetched?.version ?? null,
        t: runner.state.t,
        clients: sockets.size,
        mqtt: client ? { connected: client.connected, topics: telemetry.topics, ...mqttStats } : null,
        history: log ? { pending: log.pending, sent: log.sent, dropped: log.dropped } : null,
      });
      return;
    }
    if (req.method === 'POST' && url.pathname === '/ingest') {
      if (ingestToken && req.headers.authorization !== `Bearer ${ingestToken}`) {
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
      measure(parsed.readings);
      respond(res, 202, { accepted: parsed.readings.length });
      return;
    }
    respond(res, 404, { errors: ['Not found'] });
  });

  const wss = new WebSocketServer({
    server,
    path: '/ws',
    maxPayload: 64 * 1024,
    verifyClient: ({ origin, req }: { origin?: string; req: IncomingMessage }) =>
      isAllowedOrigin(origin, options.origins) && (key === null || access(req) !== null),
  });
  // With a control plane, a browser needs a ticket it signed for this twin.
  const access = (req: IncomingMessage): TicketAccess | null => {
    if (key === null || fetched === null) return null;
    const ticket = new URL(req.url ?? '/', 'http://gateway').searchParams.get('ticket');
    return verifyTicket(ticket, key, fetched.twinId);
  };
  const hello = (socket: WebSocket) => {
    const frames = [];
    for (let i = 0; i < history.length; i++) frames.push(history.at(i)!);
    const tick = runner.tick(0);
    send(socket, { type: 'hello', definition, speed: runner.speed, ...tick, history: frames });
  };
  wss.on('connection', (socket, req) => {
    const granted = access(req);
    sockets.set(socket, granted);
    hello(socket);
    socket.on('close', () => sockets.delete(socket));
    socket.on('message', (data) => {
      try {
        const message = JSON.parse((data as Buffer).toString()) as ClientMessage;
        if (granted !== null && !granted.operate) throw new Error('This connection can watch but not operate');
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
    for (const s of sockets.keys()) s.send(payload);
  }, TICK_MS);

  // Follow the control plane: a new version restarts the model on it, and
  // every open browser is greeted again with the new definition.
  let polling = false;
  const poll = cp
    ? setInterval(async () => {
        if (polling) return;
        polling = true;
        try {
          const next = await fetchDefinition(cp, fetched?.etag ?? null);
          if (next) {
            fetched = next;
            boot(next.definition, Date.now());
            for (const s of sockets.keys()) hello(s);
          }
        } catch (error) {
          console.warn(`Gateway: ${error instanceof Error ? error.message : String(error)}`);
        } finally {
          polling = false;
        }
      }, cp.pollMs ?? 5000)
    : undefined;

  const flush = log
    ? setInterval(() => {
        log.flush().catch((error: unknown) => console.warn(`Gateway: ${error instanceof Error ? error.message : String(error)}`));
      }, cp?.flushMs ?? 10_000)
    : undefined;

  const port = options.port ?? 8787;
  const host = options.host ?? '127.0.0.1';
  return new Promise<{
    port: number;
    runner: SimRunner;
    version: number | null;
    log: ReadingLog | null;
    close: () => Promise<void>;
  }>((done) => {
    server.listen(port, host, () => {
      const address = server.address();
      done({
        port: typeof address === 'object' && address ? address.port : port,
        get runner() {
          return runner;
        },
        get version() {
          return fetched?.version ?? null;
        },
        log,
        close: () =>
          new Promise((closed) => {
            clearInterval(timer);
            clearInterval(poll);
            clearInterval(flush);
            client?.end(true);
            // What's left goes to the history on the way out, if it can.
            void log?.flush().catch(() => undefined);
            for (const s of sockets.keys()) s.terminate();
            wss.close();
            server.close(() => closed());
          }),
      });
    });
  });
}

if (process.argv[1] === import.meta.filename) {
  const port = Number(process.env.GATEWAY_PORT ?? 8787);
  const { MERIDIAN_URL: url, MERIDIAN_GATEWAY_TOKEN: token } = process.env;
  const gateway = await startGateway({
    definitionPath: process.argv[2],
    controlPlane:
      url && token
        ? {
            url,
            token,
            publicUrl: process.env.GATEWAY_PUBLIC_URL ?? `ws://127.0.0.1:${port}/ws`,
            pollMs: Number(process.env.GATEWAY_POLL_MS ?? 5000),
          }
        : undefined,
    mqtt: process.env.MQTT_URL
      ? { url: process.env.MQTT_URL, username: process.env.MQTT_USERNAME, password: process.env.MQTT_PASSWORD }
      : undefined,
    port,
    host: process.env.GATEWAY_HOST,
    token: process.env.GATEWAY_TOKEN,
    origins: process.env.GATEWAY_ORIGINS?.split(',').filter(Boolean),
  });
  const source = gateway.version === null ? 'from file' : `version ${gateway.version} from ${url}`;
  console.log(`Meridian gateway on http://127.0.0.1:${gateway.port}, twin ${source} (socket at /ws, readings at POST /ingest)`);
}

import { readFileSync } from 'node:fs';
import {
    createServer,
    type IncomingMessage,
    type ServerResponse,
} from 'node:http';
import { resolve } from 'node:path';
import { connect as connectMqtt, type IClientOptions } from 'mqtt';
import { WebSocketServer, type WebSocket } from 'ws';
import { History } from '../resources/js/twin/core/history.ts';
import { parseReadings } from '../resources/js/twin/core/ingest.ts';
import {
    compileModbus,
    type Modbus,
} from '../resources/js/twin/core/modbus.ts';
import {
    compileOpcua,
    type OpcUa,
    type OpcUaWrite,
} from '../resources/js/twin/core/opcua.ts';
import type {
    ClientMessage,
    OpcUaBrowseResult,
    Reading,
    ServerMessage,
} from '../resources/js/twin/core/protocol.ts';
import { siteTime, SimRunner } from '../resources/js/twin/core/runner.ts';
import {
    compileTelemetry,
    type Telemetry,
} from '../resources/js/twin/core/telemetry.ts';
import type { TwinDefinition } from '../resources/js/twin/core/types.ts';
import { parseDefinition } from '../resources/js/twin/core/validate.ts';
import { AlertWatch } from './alerts.ts';
import {
    ModbusPoller,
    type ModbusDeviceConfig,
    type ModbusStatus,
} from './modbus.ts';
import {
    OBJECTS_FOLDER,
    OpcUaBridge,
    type OpcUaOptions,
    type OpcUaServerConfig,
    type OpcUaStatus,
} from './opcua.ts';
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
 * With the control plane, those topics sit below the project's prefix
 * (`p/<key>/`), and it logs in as the twin with its gateway token unless
 * MQTT_USERNAME is set.
 *
 * With OPC UA servers (registered in the twin's project, or OPCUA_SERVERS
 * without a control plane), it monitors the nodes in the twin's `opcua`
 * mappings and takes each new value as a reading. Where the twin allows it,
 * an operator's setpoint is also written to the nodes mapped for writing,
 * and each write is recorded in the control plane.
 *
 * With Modbus TCP devices (registered in the project, or MODBUS_DEVICES
 * without a control plane), it polls the registers and bits in the twin's
 * `modbus` mappings, each at its rate, and takes every answer as readings.
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
    on(
        event: 'message',
        listener: (topic: string, payload: Buffer) => void,
    ): unknown;
    on(event: 'error', listener: (error: Error) => void): unknown;
    subscribe(topics: string[]): unknown;
    unsubscribe(topics: string[]): unknown;
    end(force?: boolean): unknown;
    readonly connected: boolean;
}

export interface MqttOptions {
    /** The broker, e.g. mqtt://localhost:1883. */
    url: string;
    /** Defaults to the control plane's login for the twin, with its gateway token as password. */
    username?: string;
    password?: string;
    /** Swaps in another client, for tests. */
    connect?: (url: string, options: IClientOptions) => MqttClientLike;
}

export interface GatewayOptions {
    definitionPath?: string;
    controlPlane?: ControlPlaneOptions;
    mqtt?: MqttOptions;
    /** OPC UA certificates and trust; `servers` stand in for the control plane's. */
    opcua?: OpcUaOptions & { servers?: OpcUaServerConfig[]; writes?: boolean };
    /** `devices` stand in for the control plane's. */
    modbus?: { devices?: ModbusDeviceConfig[] };
    port?: number;
    host?: string;
    token?: string;
    /** Extra browser origins allowed to open the socket; localhost always may. */
    origins?: string[];
    now?: number;
}

export function isAllowedOrigin(
    origin: string | undefined,
    extra: string[] = [],
): boolean {
    // Non-browser clients send no Origin; browsers always do, and cannot forge it.
    if (!origin) return true;
    if (extra.includes(origin)) return true;
    try {
        const { hostname } = new URL(origin);
        return (
            hostname === 'localhost' ||
            hostname === '127.0.0.1' ||
            hostname === '[::1]'
        );
    } catch {
        return false;
    }
}

interface Fetched {
    twinId: string;
    version: number;
    definition: TwinDefinition;
    /** The broker login, and the prefix the project's topics sit below. */
    mqtt: { username: string; topicPrefix: string } | null;
    /** The project's OPC UA servers. */
    opcua: OpcUaServerConfig[];
    /** Whether operators' setpoints may be written to them. */
    opcuaWrites: boolean;
    /** The project's Modbus devices. */
    modbus: ModbusDeviceConfig[];
    etag: string | null;
}

/**
 * Fetches the twin's latest version; null when it hasn't changed since `etag`.
 * Each call checks in, reporting where browsers reach this gateway and
 * whether it is connected to the broker.
 */
export async function fetchDefinition(
    cp: ControlPlaneOptions,
    etag: string | null = null,
    mqtt?: 'connected' | 'disconnected',
    opcua?: Record<string, OpcUaStatus>,
    modbus?: Record<string, ModbusStatus>,
): Promise<Fetched | null> {
    const headers: Record<string, string> = {
        authorization: `Bearer ${cp.token}`,
        accept: 'application/json',
    };
    if (cp.publicUrl) headers['x-gateway-url'] = cp.publicUrl;
    if (mqtt) headers['x-gateway-mqtt'] = mqtt;
    if (opcua) headers['x-gateway-opcua'] = JSON.stringify(opcua);
    if (modbus) headers['x-gateway-modbus'] = JSON.stringify(modbus);
    if (etag) headers['if-none-match'] = etag;
    // A hung request would otherwise stall every later poll, and the check-ins with them.
    const res = await fetch(
        `${cp.url.replace(/\/+$/, '')}/api/gateway/definition`,
        {
            headers,
            signal: AbortSignal.timeout(10_000),
        },
    );
    if (res.status === 304) return null;
    if (!res.ok)
        throw new Error(
            `Control plane answered ${res.status} for the twin definition`,
        );
    const body = (await res.json()) as {
        twin: { id: string };
        version: number;
        definition: unknown;
        mqtt?: Fetched['mqtt'];
        opcua?: { servers: OpcUaServerConfig[]; writes?: boolean };
        modbus?: { devices: ModbusDeviceConfig[] };
    };
    return {
        twinId: body.twin.id,
        version: body.version,
        definition: parseDefinition(body.definition),
        mqtt: body.mqtt ?? null,
        opcua: body.opcua?.servers ?? [],
        opcuaWrites: body.opcua?.writes === true,
        modbus: body.modbus?.devices ?? [],
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
        const path =
            options.definitionPath ??
            resolve(import.meta.dirname, '../public/samples/northshore.json');
        definition = parseDefinition(JSON.parse(readFileSync(path, 'utf8')));
    }
    const key = cp ? ticketKey(cp.token) : null;
    const ingestToken = options.token ?? cp?.token;

    const log = cp ? new ReadingLog(cp) : null;
    const alerts = new AlertWatch(cp ?? null, definition);
    /** Feeds the model and the history alike, from /ingest or MQTT. */
    const measure = (readings: Reading[]) => {
        for (const r of readings)
            runner.measure(`${r.asset}.${r.metric}`, r.value, r.ttlS);
        log?.add(readings);
    };

    let runner!: SimRunner;
    let history!: History;
    let telemetry: Telemetry = compileTelemetry({
        ...definition,
        telemetry: [],
    });
    const topicPrefix = fetched?.mqtt?.topicPrefix ?? '';
    let opcuaServers: OpcUaServerConfig[] =
        fetched?.opcua ?? options.opcua?.servers ?? [];
    let opcua: OpcUa = compileOpcua({ ...definition, opcua: [] }, []);
    let bridge: OpcUaBridge | null = null;
    let opcuaWrites = fetched?.opcuaWrites ?? options.opcua?.writes ?? false;
    /**
     * Each node's latest value. A server only says when a value changes, so
     * these are measured again when a new version restarts the model.
     */
    const latestOpcua = new Map<
        string,
        { server: string; node: string; value: unknown }
    >();
    const opcuaStats = {
        received: 0,
        mapped: 0,
        unmapped: 0,
        written: 0,
        failedWrites: 0,
    };
    /** Monitors what the twin maps on the project's servers, starting the bridge on first use. */
    const followOpcua = (def: TwinDefinition) => {
        opcua = compileOpcua(
            def,
            opcuaServers.map((s) => s.name),
        );
        for (const problem of opcua.skipped)
            console.warn(`Gateway: ${problem}`);
        if (!bridge && opcua.servers.size === 0) return;
        openBridge()
            .follow(opcuaServers, opcua.servers)
            .catch((error: unknown) =>
                console.warn(
                    `Gateway: OPC UA ${error instanceof Error ? error.message : String(error)}`,
                ),
            );
    };
    const openBridge = () =>
        (bridge ??= new OpcUaBridge((server, node, value) => {
            opcuaStats.received++;
            latestOpcua.set(`${server}\n${node}`, { server, node, value });
            const { readings, problems } = opcua.read(server, node, value);
            for (const problem of problems) console.warn(`Gateway: ${problem}`);
            if (readings.length === 0) {
                opcuaStats.unmapped++;
                return;
            }
            opcuaStats.mapped++;
            measure(readings);
        }, options.opcua));
    let modbusDevices: ModbusDeviceConfig[] =
        fetched?.modbus ?? options.modbus?.devices ?? [];
    let modbus: Modbus = compileModbus({ ...definition, modbus: [] }, []);
    let poller: ModbusPoller | null = null;
    const modbusStats = { received: 0, mapped: 0, unmapped: 0 };
    /** Polls what the twin maps on the project's Modbus devices, starting the poller on first use. */
    const followModbus = (def: TwinDefinition) => {
        modbus = compileModbus(
            def,
            modbusDevices.map((d) => d.name),
        );
        for (const problem of modbus.skipped)
            console.warn(`Gateway: ${problem}`);
        if (!poller && modbus.devices.size === 0) return;
        poller ??= new ModbusPoller((device, read, values) => {
            modbusStats.received++;
            const { readings, problems } = modbus.read(device, read, values);
            for (const problem of problems) console.warn(`Gateway: ${problem}`);
            if (readings.length === 0) {
                modbusStats.unmapped++;
                return;
            }
            modbusStats.mapped++;
            measure(readings);
        });
        poller.follow(modbusDevices, modbus.devices);
    };
    /** Sockets with a browse under way: one at a time each, so nobody floods a PLC. */
    const browsing = new Set<WebSocket>();
    const browse = (
        socket: WebSocket,
        message: Extract<ClientMessage, { type: 'opcua-browse' }>,
    ) => {
        const node = message.node ?? OBJECTS_FOLDER;
        const answer = (
            result: OpcUaBrowseResult | { node: string; error: string },
        ) => {
            browsing.delete(socket);
            if (socket.readyState === socket.OPEN)
                send(socket, {
                    type: 'opcua-browse',
                    id: message.id,
                    server: message.server,
                    ...result,
                });
        };
        const config = opcuaServers.find((s) => s.name === message.server);
        if (!config)
            return answer({
                node,
                error: `The project has no OPC UA server called ${message.server}`,
            });
        if (browsing.has(socket))
            return answer({
                node,
                error: 'Still browsing; wait for the last answer',
            });
        browsing.add(socket);
        openBridge()
            .browse(config, node)
            .then(answer, (error: unknown) =>
                answer({
                    node,
                    error:
                        error instanceof Error ? error.message : String(error),
                }),
            );
    };
    /**
     * Sends an operator's setpoint on to the OPC UA nodes mapped for writing
     * it, if the twin allows writes, and records each one: in the control
     * plane's audit log, or the console without one.
     */
    const writeSetpoint = (
        command: { asset: string; metric: string; value: number },
        user: string | null,
        socket: WebSocket,
    ) => {
        const targets = opcua.writes(
            command.asset,
            command.metric,
            command.value,
        );
        if (targets.length === 0) return;
        if (!opcuaWrites || !bridge) {
            send(socket, {
                type: 'error',
                message: `Writing to OPC UA is off for this twin, so only the model's ${command.asset} ${command.metric} changed`,
            });
            return;
        }
        for (const target of targets) {
            bridge.write(target.server, target.node, target.value).then(
                () => record(target, user, null),
                (error: unknown) => {
                    const message =
                        error instanceof Error ? error.message : String(error);
                    if (socket.readyState === socket.OPEN)
                        send(socket, {
                            type: 'error',
                            message: `Couldn't write ${target.node} on ${target.server}: ${message}`,
                        });
                    record(target, user, message);
                },
            );
        }
    };
    const record = (
        write: OpcUaWrite,
        user: string | null,
        error: string | null,
    ) => {
        if (error === null) opcuaStats.written++;
        else opcuaStats.failedWrites++;
        const entry = {
            ...write,
            user,
            succeeded: error === null,
            error,
            at: Date.now(),
        };
        console.log(`Gateway: OPC UA write ${JSON.stringify(entry)}`);
        if (!cp) return;
        fetch(`${cp.url.replace(/\/+$/, '')}/api/gateway/opcua-writes`, {
            method: 'POST',
            headers: {
                authorization: `Bearer ${cp.token}`,
                accept: 'application/json',
                'content-type': 'application/json',
            },
            body: JSON.stringify(entry),
            signal: AbortSignal.timeout(10_000),
        })
            .then((res) => {
                if (!res.ok)
                    throw new Error(`the control plane answered ${res.status}`);
            })
            .catch((failure: unknown) =>
                console.warn(
                    `Gateway: OPC UA write not recorded: ${failure instanceof Error ? failure.message : String(failure)}`,
                ),
            );
    };
    const opcuaStatuses = () =>
        bridge
            ? Object.fromEntries(
                  Object.entries(bridge.health()).map(([name, h]) => [
                      name,
                      h.status,
                  ]),
              )
            : undefined;
    const modbusStatuses = () =>
        poller
            ? Object.fromEntries(
                  Object.entries(poller.health()).map(([name, h]) => [
                      name,
                      h.status,
                  ]),
              )
            : undefined;

    const boot = (def: TwinDefinition, now: number) => {
        definition = def;
        followOpcua(def);
        followModbus(def);
        const compiled = compileTelemetry(def, topicPrefix);
        for (const problem of compiled.skipped)
            console.warn(`Gateway: ${problem}`);
        const before = telemetry.topics;
        telemetry = compiled;
        resubscribe(before);
        const start = siteTime(now, def.utcOffsetHours, 14) - PREROLL_S * 1000;
        runner = new SimRunner(def, start);
        history = new History(HISTORY_FRAMES);
        // The rules see the preroll too, so hold times and change windows are
        // primed, and whatever is open after it is open now.
        alerts.reset(def);
        for (const f of runner.preroll(PREROLL_S)) {
            history.push(f);
            alerts.evaluate(f);
        }
        for (const { server, node, value } of latestOpcua.values())
            for (const r of opcua.read(server, node, value).readings)
                runner.measure(`${r.asset}.${r.metric}`, r.value, r.ttlS);
    };
    const mqttStats = { received: 0, mapped: 0, unmapped: 0 };
    const client = options.mqtt
        ? (
              options.mqtt.connect ??
              (connectMqtt as unknown as NonNullable<MqttOptions['connect']>)
          )(options.mqtt.url, {
              username: options.mqtt.username ?? fetched?.mqtt?.username,
              password: options.mqtt.username
                  ? options.mqtt.password
                  : (cp?.token ?? options.mqtt.password),
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
    client?.on('error', (error) =>
        console.warn(`Gateway: MQTT ${error.message}`),
    );
    client?.on('message', (topic, payload) => {
        mqttStats.received++;
        const { readings, problems } = telemetry.read(
            topic,
            payload.toString('utf8'),
        );
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
    const send = (socket: WebSocket, message: ServerMessage) =>
        socket.send(JSON.stringify(message));

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
                mqtt: client
                    ? {
                          connected: client.connected,
                          topics: telemetry.topics,
                          ...mqttStats,
                      }
                    : null,
                opcua: bridge
                    ? {
                          servers: bridge.health(),
                          writes: opcuaWrites,
                          ...opcuaStats,
                      }
                    : null,
                modbus: poller
                    ? { devices: poller.health(), ...modbusStats }
                    : null,
                history: log
                    ? {
                          pending: log.pending,
                          sent: log.sent,
                          dropped: log.dropped,
                      }
                    : null,
                alerts: {
                    open: alerts.open().length,
                    sent: alerts.sent,
                    failed: alerts.failed,
                },
            });
            return;
        }
        if (req.method === 'POST' && url.pathname === '/ingest') {
            if (
                ingestToken &&
                req.headers.authorization !== `Bearer ${ingestToken}`
            ) {
                respond(res, 401, {
                    errors: ['Missing or wrong bearer token'],
                });
                return;
            }
            let body: unknown;
            try {
                body = JSON.parse(await readBody(req));
            } catch {
                respond(res, 400, {
                    errors: ['Body must be JSON, at most 256 KiB'],
                });
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
        verifyClient: ({
            origin,
            req,
        }: {
            origin?: string;
            req: IncomingMessage;
        }) =>
            isAllowedOrigin(origin, options.origins) &&
            (key === null || access(req) !== null),
    });
    // With a control plane, a browser needs a ticket it signed for this twin.
    const access = (req: IncomingMessage): TicketAccess | null => {
        if (key === null || fetched === null) return null;
        const ticket = new URL(
            req.url ?? '/',
            'http://gateway',
        ).searchParams.get('ticket');
        return verifyTicket(ticket, key, fetched.twinId);
    };
    const hello = (socket: WebSocket) => {
        const frames = [];
        for (let i = 0; i < history.length; i++) frames.push(history.at(i)!);
        const tick = runner.tick(0);
        send(socket, {
            type: 'hello',
            definition,
            speed: runner.speed,
            ...tick,
            history: frames,
        });
    };
    wss.on('connection', (socket, req) => {
        const granted = access(req);
        sockets.set(socket, granted);
        hello(socket);
        // A share link's ticket ends the connection with it (4001: come back for another).
        const ending =
            granted?.until === undefined
                ? undefined
                : setTimeout(
                      () => socket.close(4001, 'Ticket expired'),
                      Math.max(0, granted.until - Date.now()),
                  );
        socket.on('close', () => {
            clearTimeout(ending);
            sockets.delete(socket);
            browsing.delete(socket);
        });
        socket.on('message', (data) => {
            try {
                const message = JSON.parse(
                    (data as Buffer).toString(),
                ) as ClientMessage;
                if (message.type === 'opcua-browse') {
                    if (granted !== null && !granted.edit)
                        throw new Error(
                            'Only editors can browse OPC UA servers',
                        );
                    browse(socket, message);
                    return;
                }
                if (granted !== null && !granted.operate)
                    throw new Error(
                        'This connection can watch but not operate',
                    );
                if (message.type === 'command') {
                    runner.command(message.command);
                    const command = message.command;
                    if (
                        command.type === 'setpoint' &&
                        command.metric !== undefined
                    )
                        writeSetpoint(
                            {
                                asset: command.asset,
                                metric: command.metric,
                                value: command.value,
                            },
                            granted?.user ?? null,
                            socket,
                        );
                } else if (
                    message.type === 'speed' &&
                    [0, 1, 60, 600].includes(message.speed)
                )
                    runner.speed = message.speed;
                else throw new Error('Unknown message');
            } catch (error) {
                send(socket, {
                    type: 'error',
                    message:
                        error instanceof Error ? error.message : 'Bad message',
                });
            }
        });
    });

    let last = performance.now();
    const timer = setInterval(() => {
        const t = performance.now();
        const tick = runner.tick((t - last) / 1000);
        last = t;
        for (const f of tick.history) {
            history.push(f);
            const { raised, cleared } = alerts.evaluate(f);
            for (const a of raised)
                console.log(
                    `Gateway: alert raised, ${a.severity}: ${a.message}`,
                );
            for (const a of cleared)
                console.log(`Gateway: alert cleared: ${a.message}`);
        }
        void alerts.report();
        if (sockets.size === 0) return;
        const payload = JSON.stringify({
            type: 'tick',
            ...tick,
            speed: runner.speed,
        } satisfies ServerMessage);
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
                  const next = await fetchDefinition(
                      cp,
                      fetched?.etag ?? null,
                      client
                          ? client.connected
                              ? 'connected'
                              : 'disconnected'
                          : undefined,
                      opcuaStatuses(),
                      modbusStatuses(),
                  );
                  if (next) {
                      const changed = next.version !== fetched?.version;
                      fetched = next;
                      opcuaServers = next.opcua;
                      opcuaWrites = next.opcuaWrites;
                      modbusDevices = next.modbus;
                      // Only a new version restarts the model; a changed server, device or
                      // the write switch only changes what OPC UA and Modbus do.
                      if (changed) {
                          boot(next.definition, Date.now());
                          for (const s of sockets.keys()) hello(s);
                      } else {
                          followOpcua(definition);
                          followModbus(definition);
                      }
                  }
              } catch (error) {
                  console.warn(
                      `Gateway: ${error instanceof Error ? error.message : String(error)}`,
                  );
              } finally {
                  polling = false;
              }
          }, cp.pollMs ?? 5000)
        : undefined;

    const flush = log
        ? setInterval(() => {
              log.flush().catch((error: unknown) =>
                  console.warn(
                      `Gateway: ${error instanceof Error ? error.message : String(error)}`,
                  ),
              );
          }, cp?.flushMs ?? 10_000)
        : undefined;

    const port = options.port ?? 8787;
    const host = options.host ?? '127.0.0.1';
    return new Promise<{
        port: number;
        runner: SimRunner;
        version: number | null;
        log: ReadingLog | null;
        alerts: AlertWatch;
        close: () => Promise<void>;
    }>((done) => {
        server.listen(port, host, () => {
            const address = server.address();
            done({
                port:
                    typeof address === 'object' && address
                        ? address.port
                        : port,
                get runner() {
                    return runner;
                },
                get version() {
                    return fetched?.version ?? null;
                },
                log,
                alerts,
                close: () =>
                    new Promise((closed) => {
                        clearInterval(timer);
                        clearInterval(poll);
                        clearInterval(flush);
                        client?.end(true);
                        void bridge?.close();
                        poller?.close();
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

/**
 * OPC UA servers for a gateway without a control plane, as JSON:
 * `[{"name": "Plant PLC", "endpoint": "opc.tcp://plc:4840"}]`, anonymous and
 * unsecured unless a server says otherwise.
 */
export function standaloneServers(json: string): OpcUaServerConfig[] {
    const list = JSON.parse(json) as Partial<OpcUaServerConfig>[];
    return list.map((s) => ({
        name: String(s.name),
        endpoint: String(s.endpoint),
        securityMode: s.securityMode ?? 'none',
        securityPolicy: s.securityPolicy ?? 'None',
        username: s.username ?? null,
        password: s.password ?? null,
    }));
}

/**
 * Modbus devices for a gateway without a control plane, as JSON:
 * `[{"name": "Main meter", "host": "10.0.0.5"}]`, on port 502 and unit 1
 * unless a device says otherwise.
 */
export function standaloneDevices(json: string): ModbusDeviceConfig[] {
    const list = JSON.parse(json) as Partial<ModbusDeviceConfig>[];
    return list.map((d) => ({
        name: String(d.name),
        host: String(d.host),
        port: d.port ?? 502,
        unitId: d.unitId ?? 1,
    }));
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
                      publicUrl:
                          process.env.GATEWAY_PUBLIC_URL ??
                          `ws://127.0.0.1:${port}/ws`,
                      pollMs: Number(process.env.GATEWAY_POLL_MS ?? 5000),
                  }
                : undefined,
        mqtt: process.env.MQTT_URL
            ? {
                  url: process.env.MQTT_URL,
                  username: process.env.MQTT_USERNAME,
                  password: process.env.MQTT_PASSWORD,
              }
            : undefined,
        opcua: {
            pkiDir: process.env.OPCUA_PKI_DIR,
            acceptUnknownCertificates:
                process.env.OPCUA_ACCEPT_UNKNOWN_CERTIFICATES === '1',
            servers: process.env.OPCUA_SERVERS
                ? standaloneServers(process.env.OPCUA_SERVERS)
                : undefined,
            writes: process.env.OPCUA_WRITES === '1',
        },
        modbus: {
            devices: process.env.MODBUS_DEVICES
                ? standaloneDevices(process.env.MODBUS_DEVICES)
                : undefined,
        },
        port,
        host: process.env.GATEWAY_HOST,
        token: process.env.GATEWAY_TOKEN,
        origins: process.env.GATEWAY_ORIGINS?.split(',').filter(Boolean),
    });
    const source =
        gateway.version === null
            ? 'from file'
            : `version ${gateway.version} from ${url}`;
    console.log(
        `Meridian gateway on http://127.0.0.1:${gateway.port}, twin ${source} (socket at /ws, readings at POST /ingest)`,
    );
}

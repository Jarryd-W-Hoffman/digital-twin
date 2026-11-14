import { connect, type Socket } from 'node:net';
import type { ModbusRead } from '../resources/js/twin/core/modbus.ts';
import type { ModbusTable } from '../resources/js/twin/core/types.ts';

/**
 * The gateway's Modbus TCP side: one connection per device the twin's
 * mappings use, each asking for its ranges of registers and bits on their
 * own timers. Values are handed to `onValues`; turning them into readings is
 * the core's job (resources/js/twin/core/modbus.ts).
 *
 * The protocol is small enough to speak directly: a seven-byte header (a
 * transaction id, protocol 0, the length that follows, and the unit id),
 * then a function code and its data. Only reads are sent: 1 (coils),
 * 2 (discrete inputs), 3 (holding registers) and 4 (input registers).
 */

/** A device registered in the twin's project, as the control plane sends it. */
export interface ModbusDeviceConfig {
    name: string;
    host: string;
    port: number;
    unitId: number;
}

export type ModbusStatus =
    | 'connecting'
    | 'connected'
    | 'reconnecting'
    | 'failed';

export interface ModbusDeviceHealth {
    status: ModbusStatus;
    reads: number;
    /** The last connection error, and reads the device refused. */
    error?: string;
    badReads?: Record<string, string>;
}

export const FUNCTION_CODES: Record<ModbusTable, number> = {
    coil: 1,
    discrete: 2,
    holding: 3,
    input: 4,
};

const EXCEPTIONS: Record<number, string> = {
    1: 'illegal function',
    2: 'illegal data address',
    3: 'illegal data value',
    4: 'device failure',
    5: 'acknowledge',
    6: 'device busy',
    10: 'gateway path unavailable',
    11: 'gateway target failed to respond',
};

const REQUEST_TIMEOUT_MS = 3000;
const settingsOf = (config: ModbusDeviceConfig) => JSON.stringify(config);
const messageOf = (error: unknown) =>
    error instanceof Error ? error.message : String(error);

/** A request for a range, as its frame. */
export function requestFrame(
    transaction: number,
    unitId: number,
    table: ModbusTable,
    start: number,
    count: number,
): Buffer {
    const frame = Buffer.alloc(12);
    frame.writeUInt16BE(transaction, 0);
    frame.writeUInt16BE(0, 2);
    frame.writeUInt16BE(6, 4);
    frame.writeUInt8(unitId, 6);
    frame.writeUInt8(FUNCTION_CODES[table], 7);
    frame.writeUInt16BE(start, 8);
    frame.writeUInt16BE(count, 10);
    return frame;
}

/**
 * One device's connection: requests go one at a time, as many devices and
 * serial gateways only handle one.
 */
export class ModbusClient {
    private socket?: Socket;
    private buffer = Buffer.alloc(0);
    private transaction = 0;
    private waiting?: {
        transaction: number;
        table: ModbusTable;
        count: number;
        resolve: (values: number[]) => void;
        reject: (error: Error) => void;
        timer: ReturnType<typeof setTimeout>;
    };
    private queue: Promise<unknown> = Promise.resolve();
    onClose?: (error: Error) => void;

    constructor(readonly config: ModbusDeviceConfig) {}

    connect(): Promise<void> {
        return new Promise((resolve, reject) => {
            const socket = connect({
                host: this.config.host,
                port: this.config.port,
            });
            const timer = setTimeout(() => {
                socket.destroy();
                reject(
                    new Error(
                        `no answer from ${this.config.host}:${this.config.port}`,
                    ),
                );
            }, REQUEST_TIMEOUT_MS);
            socket.once('connect', () => {
                clearTimeout(timer);
                socket.setNoDelay(true);
                this.socket = socket;
                resolve();
            });
            socket.on('data', (data: Buffer) => this.receive(data));
            socket.on('error', (error) => {
                clearTimeout(timer);
                reject(error);
                this.drop(error);
            });
            socket.on('close', () =>
                this.drop(new Error('the device closed the connection')),
            );
        });
    }

    /** Reads a range: registers as unsigned 16-bit numbers, bits as 1 or 0. */
    read(table: ModbusTable, start: number, count: number): Promise<number[]> {
        const next = this.queue.then(() => this.send(table, start, count));
        this.queue = next.catch(() => undefined);
        return next;
    }

    close(): void {
        this.onClose = undefined;
        this.socket?.destroy();
        this.socket = undefined;
    }

    private send(
        table: ModbusTable,
        start: number,
        count: number,
    ): Promise<number[]> {
        const socket = this.socket;
        if (!socket || socket.destroyed)
            return Promise.reject(new Error('not connected'));
        this.transaction = (this.transaction + 1) % 0x10000;
        const transaction = this.transaction;
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                this.waiting = undefined;
                reject(
                    new Error(
                        `no answer within ${REQUEST_TIMEOUT_MS / 1000} s`,
                    ),
                );
            }, REQUEST_TIMEOUT_MS);
            this.waiting = {
                transaction,
                table,
                count,
                resolve,
                reject,
                timer,
            };
            socket.write(
                requestFrame(
                    transaction,
                    this.config.unitId,
                    table,
                    start,
                    count,
                ),
            );
        });
    }

    private receive(data: Buffer): void {
        this.buffer = Buffer.concat([this.buffer, data]);
        while (this.buffer.length >= 7) {
            const length = 6 + this.buffer.readUInt16BE(4);
            if (this.buffer.length < length) return;
            const frame = this.buffer.subarray(0, length);
            this.buffer = this.buffer.subarray(length);
            const waiting = this.waiting;
            if (!waiting || frame.readUInt16BE(0) !== waiting.transaction)
                continue;
            clearTimeout(waiting.timer);
            this.waiting = undefined;
            const code = frame.readUInt8(7);
            if (code & 0x80) {
                const exception = frame.readUInt8(8);
                waiting.reject(
                    new Error(
                        EXCEPTIONS[exception] ?? `exception ${exception}`,
                    ),
                );
                continue;
            }
            const bytes = frame.subarray(9, 9 + frame.readUInt8(8));
            const values: number[] = [];
            if (waiting.table === 'coil' || waiting.table === 'discrete') {
                for (let i = 0; i < waiting.count && i < bytes.length * 8; i++)
                    values.push((bytes[i >> 3] >> (i & 7)) & 1);
            } else {
                for (let i = 0; i + 1 < bytes.length; i += 2)
                    values.push(bytes.readUInt16BE(i));
            }
            waiting.resolve(values);
        }
    }

    private drop(error: Error): void {
        if (this.waiting) {
            clearTimeout(this.waiting.timer);
            this.waiting.reject(error);
            this.waiting = undefined;
        }
        if (!this.socket) return;
        this.socket = undefined;
        this.buffer = Buffer.alloc(0);
        this.onClose?.(error);
    }
}

const labelOf = (read: ModbusRead) =>
    `${read.table} ${read.start}-${read.start + read.count - 1}`;

class Connection {
    status: ModbusStatus = 'connecting';
    error?: string;
    readonly badReads = new Map<string, string>();
    private client: ModbusClient;
    private reads: ModbusRead[] = [];
    private timers: ReturnType<typeof setInterval>[] = [];
    private busy = new Set<ModbusRead>();
    private retry?: ReturnType<typeof setTimeout>;
    private delay = 1000;
    private closed = false;
    private everConnected = false;

    constructor(
        readonly config: ModbusDeviceConfig,
        private readonly onValues: (read: ModbusRead, values: number[]) => void,
    ) {
        this.client = new ModbusClient(config);
        void this.start();
    }

    get readCount(): number {
        return this.reads.length;
    }

    follow(reads: ModbusRead[]): void {
        this.reads = reads;
        for (const key of this.badReads.keys())
            if (!reads.some((r) => labelOf(r) === key))
                this.badReads.delete(key);
        if (this.status === 'connected') this.schedule();
    }

    close(): void {
        this.closed = true;
        clearTimeout(this.retry);
        this.stop();
        this.client.close();
    }

    private async start(): Promise<void> {
        if (this.closed) return;
        this.client = new ModbusClient(this.config);
        this.client.onClose = (error) => this.lost(error);
        try {
            await this.client.connect();
        } catch (error) {
            if (this.closed) return;
            this.status = this.everConnected ? 'reconnecting' : 'failed';
            this.error = messageOf(error);
            this.client.close();
            this.retry = setTimeout(() => void this.start(), this.delay);
            this.delay = Math.min(this.delay * 2, 10_000);
            return;
        }
        if (this.closed) return this.client.close();
        this.status = 'connected';
        this.everConnected = true;
        this.error = undefined;
        this.delay = 1000;
        this.schedule();
    }

    private lost(error: Error): void {
        if (this.closed) return;
        this.stop();
        this.status = 'reconnecting';
        this.error = error.message;
        this.retry = setTimeout(() => void this.start(), this.delay);
    }

    private schedule(): void {
        this.stop();
        for (const read of this.reads) {
            const poll = () => void this.poll(read);
            poll();
            this.timers.push(setInterval(poll, read.pollMs));
        }
    }

    private stop(): void {
        for (const timer of this.timers) clearInterval(timer);
        this.timers = [];
    }

    private async poll(read: ModbusRead): Promise<void> {
        if (this.busy.has(read)) return;
        this.busy.add(read);
        try {
            const values = await this.client.read(
                read.table,
                read.start,
                read.count,
            );
            this.badReads.delete(labelOf(read));
            this.onValues(read, values);
        } catch (error) {
            if (this.status === 'connected')
                this.badReads.set(labelOf(read), messageOf(error));
        } finally {
            this.busy.delete(read);
        }
    }
}

/**
 * The Modbus devices a twin reads: connects to those with something mapped,
 * and asks each for its ranges at their rates.
 */
export class ModbusPoller {
    private readonly connections = new Map<string, Connection>();

    constructor(
        private readonly onValues: (
            device: string,
            read: ModbusRead,
            values: number[],
        ) => void,
    ) {}

    /**
     * Connects to the devices that have reads and polls them; disconnects
     * from the rest, and reconnects a device whose settings changed.
     */
    follow(
        configs: ModbusDeviceConfig[],
        wanted: Map<string, ModbusRead[]>,
    ): void {
        const byName = new Map(configs.map((c) => [c.name, c]));
        for (const [name, connection] of this.connections) {
            const config = byName.get(name);
            if (
                config &&
                wanted.has(name) &&
                settingsOf(config) === settingsOf(connection.config)
            )
                continue;
            this.connections.delete(name);
            connection.close();
        }
        for (const [name, reads] of wanted) {
            const config = byName.get(name);
            if (!config) continue;
            let connection = this.connections.get(name);
            if (!connection) {
                connection = new Connection(config, (read, values) =>
                    this.onValues(name, read, values),
                );
                this.connections.set(name, connection);
            }
            connection.follow(reads);
        }
    }

    health(): Record<string, ModbusDeviceHealth> {
        return Object.fromEntries(
            [...this.connections].map(([name, c]) => [
                name,
                {
                    status: c.status,
                    reads: c.readCount,
                    ...(c.error ? { error: c.error } : {}),
                    ...(c.badReads.size
                        ? { badReads: Object.fromEntries(c.badReads) }
                        : {}),
                },
            ]),
        );
    }

    close(): void {
        for (const connection of this.connections.values()) connection.close();
        this.connections.clear();
    }
}

import { createServer, type Server, type Socket } from 'node:net';
import type { ModbusTable } from '../resources/js/twin/core/types.ts';
import { freePort } from './opcua-simulator.ts';

/**
 * A small Modbus TCP device to develop and test against: 1,000 of each kind
 * of register and bit, set from outside with `set`. It answers reads
 * (function codes 1 to 4) for its unit id, and refuses anything else as a
 * real device would, with an exception.
 *
 *     npm run modbus-simulator     # 127.0.0.1:5020, unit 1: a power meter and a cold store
 */

export const SIZE = 1000;

export interface ModbusSimulator {
    port: number;
    /** Sets registers (16-bit words) or bits from an address. */
    set(table: ModbusTable, address: number, values: number[]): void;
    /** Sets a 32-bit float across two holding or input registers, high word first. */
    setFloat(table: 'holding' | 'input', address: number, value: number): void;
    /** How many requests it has answered. */
    readonly requests: number;
    /** Drops every open connection, as a device losing power would. */
    drop(): void;
    close(): Promise<void>;
}

const TABLES: Record<number, ModbusTable> = {
    1: 'coil',
    2: 'discrete',
    3: 'holding',
    4: 'input',
};

export async function startModbusSimulator(
    options: { port?: number; unitId?: number } = {},
): Promise<ModbusSimulator> {
    const unitId = options.unitId ?? 1;
    const memory: Record<ModbusTable, number[]> = {
        coil: Array.from({ length: SIZE }, () => 0),
        discrete: Array.from({ length: SIZE }, () => 0),
        holding: Array.from({ length: SIZE }, () => 0),
        input: Array.from({ length: SIZE }, () => 0),
    };
    let requests = 0;
    const sockets = new Set<Socket>();

    const answer = (socket: Socket, request: Buffer) => {
        requests++;
        const header = request.subarray(0, 7);
        const code = request.readUInt8(7);
        const reply = (pdu: Buffer) => {
            const frame = Buffer.concat([header, pdu]);
            frame.writeUInt16BE(pdu.length + 1, 4);
            socket.write(frame);
        };
        const exception = (n: number) => reply(Buffer.from([code | 0x80, n]));
        if (request.readUInt8(6) !== unitId) return exception(11);
        const table = TABLES[code];
        if (!table) return exception(1);
        const start = request.readUInt16BE(8);
        const count = request.readUInt16BE(10);
        const bits = table === 'coil' || table === 'discrete';
        if (count < 1 || count > (bits ? 2000 : 125)) return exception(3);
        if (start + count > SIZE) return exception(2);
        const values = memory[table].slice(start, start + count);
        if (bits) {
            const bytes = Buffer.alloc(Math.ceil(count / 8));
            values.forEach((v, i) => {
                if (v) bytes[i >> 3] |= 1 << (i & 7);
            });
            return reply(
                Buffer.concat([Buffer.from([code, bytes.length]), bytes]),
            );
        }
        const bytes = Buffer.alloc(count * 2);
        values.forEach((v, i) => bytes.writeUInt16BE(v & 0xffff, i * 2));
        reply(Buffer.concat([Buffer.from([code, bytes.length]), bytes]));
    };

    const server: Server = createServer((socket) => {
        sockets.add(socket);
        let buffer = Buffer.alloc(0);
        socket.on('data', (data: Buffer) => {
            buffer = Buffer.concat([buffer, data]);
            while (buffer.length >= 7) {
                const length = 6 + buffer.readUInt16BE(4);
                if (buffer.length < length) return;
                answer(socket, buffer.subarray(0, length));
                buffer = buffer.subarray(length);
            }
        });
        socket.on('close', () => sockets.delete(socket));
        socket.on('error', () => undefined);
    });
    const port = options.port ?? (await freePort());
    await new Promise<void>((resolve) =>
        server.listen(port, '127.0.0.1', resolve),
    );

    return {
        port,
        set(table, address, values) {
            values.forEach((v, i) => (memory[table][address + i] = v));
        },
        setFloat(table, address, value) {
            const view = new DataView(new ArrayBuffer(4));
            view.setFloat32(0, value);
            memory[table][address] = view.getUint16(0);
            memory[table][address + 1] = view.getUint16(2);
        },
        get requests() {
            return requests;
        },
        drop() {
            for (const socket of sockets) socket.destroy();
        },
        close: () =>
            new Promise((resolve) => {
                for (const socket of sockets) socket.destroy();
                server.close(() => resolve());
            }),
    };
}

if (process.argv[1] === import.meta.filename) {
    const simulator = await startModbusSimulator({
        port: Number(process.env.MODBUS_PORT ?? 5020),
    });
    // A power meter's demand in kW (holding 0 to 1, a float), and a cold store's
    // temperature in tenths of a degree (input 0) with its door (discrete 0).
    let t = 0;
    const tick = () => {
        t++;
        simulator.setFloat('holding', 0, 42 + 6 * Math.sin(t / 30));
        simulator.set('input', 0, [
            Math.round((1 + 0.5 * Math.sin(t / 60)) * 10),
        ]);
        simulator.set('discrete', 0, [t % 120 < 10 ? 1 : 0]);
    };
    tick();
    setInterval(tick, 1000);
    console.log(
        `Modbus simulator on 127.0.0.1:${simulator.port}, unit 1: holding 0-1 a meter's kW (float32), input 0 a store's temperature (tenths of °C), discrete 0 its door`,
    );
}

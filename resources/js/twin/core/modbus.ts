import { metricsFor } from './metrics.ts';
import type { Reading } from './protocol.ts';
import type {
    ModbusMapping,
    ModbusTable,
    ModbusType,
    TwinDefinition,
} from './types.ts';

/**
 * Turning Modbus registers and bits into readings, per the twin's `modbus`
 * mappings. Kept free of any network code, so it can be tested, and reused,
 * on its own: the gateway's poller only fetches ranges and hands them here.
 */

/** How often a device is asked when a mapping doesn't say. */
export const DEFAULT_POLL_MS = 1000;

/** The most one request may ask for, per the Modbus specification. */
export const MAX_REGISTERS = 125;
export const MAX_BITS = 2000;

/** Unused addresses a read may span to save a request. */
const GAP = 16;

const isBits = (table: ModbusTable) => table === 'coil' || table === 'discrete';

/** How many registers a value takes up (bits take one address). */
export function widthOf(type: ModbusType): number {
    if (type === 'uint32' || type === 'int32' || type === 'float32') return 2;
    if (type === 'float64') return 4;
    return 1;
}

/**
 * The number in consecutive 16-bit registers, read as the type: most
 * significant register first unless the word order is little.
 */
export function decode(
    registers: number[],
    type: ModbusType,
    wordOrder: 'big' | 'little' = 'big',
): number {
    const words = registers.slice(0, widthOf(type));
    if (wordOrder === 'little') words.reverse();
    const view = new DataView(new ArrayBuffer(words.length * 2));
    words.forEach((word, i) => view.setUint16(i * 2, word & 0xffff));
    switch (type) {
        case 'int16':
            return view.getInt16(0);
        case 'uint32':
            return view.getUint32(0);
        case 'int32':
            return view.getInt32(0);
        case 'float32':
            return view.getFloat32(0);
        case 'float64':
            return view.getFloat64(0);
        case 'bool':
            return view.getUint16(0) === 0 ? 0 : 1;
        default:
            return view.getUint16(0);
    }
}

/** One request to a device, and the mappings its answer feeds. */
export interface ModbusRead {
    table: ModbusTable;
    /** The first address, and how many registers or bits. */
    start: number;
    count: number;
    pollMs: number;
    mappings: ModbusMapping[];
}

export interface Modbus {
    /** Per device name, the reads to make. */
    devices: Map<string, ModbusRead[]>;
    /** Mappings left out, and why. */
    skipped: string[];
    /** Readings from one read's answer: registers as numbers, bits as 1 or 0. */
    read(
        device: string,
        read: ModbusRead,
        values: number[],
    ): { readings: Reading[]; problems: string[] };
}

const label = (m: ModbusMapping) =>
    `Modbus ${m.device} ${m.table ?? 'holding'} ${m.address}`;

/**
 * Compiles a twin's mappings against the devices its project has, dropping
 * (and reporting) those whose device or metric doesn't exist. Mappings of a
 * device's table polled at the same rate are read together, in as few
 * requests as the specification's limits allow.
 */
export function compileModbus(
    def: TwinDefinition,
    deviceNames: Iterable<string>,
): Modbus {
    const known = new Set(deviceNames);
    const assets = new Map(def.assets.map((a) => [a.id, a]));
    const skipped: string[] = [];
    const groups = new Map<string, ModbusMapping[]>();
    for (const m of def.modbus ?? []) {
        const asset = assets.get(m.asset);
        if (!known.has(m.device)) {
            skipped.push(`${label(m)}: no device named ${m.device}`);
            continue;
        }
        if (
            !asset ||
            !metricsFor(def, asset).some((x) => x.key === m.metric && !x.hidden)
        ) {
            skipped.push(`${label(m)}: ${m.asset} has no metric ${m.metric}`);
            continue;
        }
        const width = isBits(m.table ?? 'holding')
            ? 1
            : widthOf(m.type ?? 'uint16');
        if (m.address + width > 65536) {
            skipped.push(`${label(m)}: runs past the last address`);
            continue;
        }
        const key = JSON.stringify([
            m.device,
            m.table ?? 'holding',
            m.pollMs ?? DEFAULT_POLL_MS,
        ]);
        groups.set(key, [...(groups.get(key) ?? []), m]);
    }

    const devices = new Map<string, ModbusRead[]>();
    for (const [key, mappings] of groups) {
        const [device, table, pollMs] = JSON.parse(key) as [
            string,
            ModbusTable,
            number,
        ];
        const max = isBits(table) ? MAX_BITS : MAX_REGISTERS;
        const reads = devices.get(device) ?? [];
        let current: ModbusRead | null = null;
        for (const m of [...mappings].sort((a, b) => a.address - b.address)) {
            const end =
                m.address + (isBits(table) ? 1 : widthOf(m.type ?? 'uint16'));
            if (
                current &&
                m.address <= current.start + current.count + GAP &&
                end - current.start <= max
            ) {
                current.count = Math.max(current.count, end - current.start);
                current.mappings.push(m);
            } else {
                current = {
                    table,
                    start: m.address,
                    count: end - m.address,
                    pollMs,
                    mappings: [m],
                };
                reads.push(current);
            }
        }
        devices.set(device, reads);
    }

    return {
        devices,
        skipped,
        read(_device, read, values) {
            const readings: Reading[] = [];
            const problems: string[] = [];
            for (const m of read.mappings) {
                const at = m.address - read.start;
                const type = m.type ?? 'uint16';
                const width = isBits(read.table) ? 1 : widthOf(type);
                const words = values.slice(at, at + width);
                if (words.length < width) {
                    problems.push(`${label(m)}: the device sent too little`);
                    continue;
                }
                let raw = isBits(read.table)
                    ? words[0]
                        ? 1
                        : 0
                    : decode(words, type, m.wordOrder);
                if (m.bit !== undefined && !isBits(read.table))
                    raw = (words[0] >> m.bit) & 1;
                if (!Number.isFinite(raw)) {
                    problems.push(`${label(m)}: not a number`);
                    continue;
                }
                readings.push({
                    asset: m.asset,
                    metric: m.metric,
                    value: raw * (m.scale ?? 1) + (m.offset ?? 0),
                    ...(m.ttlS === undefined ? {} : { ttlS: m.ttlS }),
                });
            }
            return { readings, problems };
        },
    };
}

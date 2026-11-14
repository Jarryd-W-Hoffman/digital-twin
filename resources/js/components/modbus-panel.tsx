import { router } from '@inertiajs/react';
import { useState } from 'react';
import { store as storeVersion } from '@/routes/twins/versions';
import { metricsFor } from '@/twin/core/metrics';
import type {
    ModbusMapping,
    ModbusTable,
    ModbusType,
    TwinDefinition,
} from '@/twin/core/types';
import { validateDefinition } from '@/twin/core/validate';

type Row = {
    device: string;
    table: ModbusTable;
    address: string;
    type: ModbusType;
    wordOrder: 'big' | 'little';
    asset: string;
    metric: string;
    scale: string;
    offset: string;
    pollMs: string;
    /** What the panel doesn't edit, kept as it was. */
    kept: Pick<ModbusMapping, 'bit' | 'ttlS'>;
};

const TABLES: { value: ModbusTable; label: string }[] = [
    { value: 'holding', label: 'Holding register' },
    { value: 'input', label: 'Input register' },
    { value: 'coil', label: 'Coil' },
    { value: 'discrete', label: 'Discrete input' },
];

const TYPES: { value: ModbusType; label: string }[] = [
    { value: 'uint16', label: 'Unsigned 16-bit' },
    { value: 'int16', label: 'Signed 16-bit' },
    { value: 'uint32', label: 'Unsigned 32-bit' },
    { value: 'int32', label: 'Signed 32-bit' },
    { value: 'float32', label: 'Float, 32-bit' },
    { value: 'float64', label: 'Float, 64-bit' },
    { value: 'bool', label: 'On or off' },
];

const isBits = (table: ModbusTable) => table === 'coil' || table === 'discrete';
const isWide = (type: ModbusType) =>
    type !== 'uint16' && type !== 'int16' && type !== 'bool';

const toRow = (m: ModbusMapping): Row => ({
    device: m.device,
    table: m.table ?? 'holding',
    address: String(m.address),
    type: m.type ?? 'uint16',
    wordOrder: m.wordOrder ?? 'big',
    asset: m.asset,
    metric: m.metric,
    scale: m.scale === undefined ? '' : String(m.scale),
    offset: m.offset === undefined ? '' : String(m.offset),
    pollMs: m.pollMs === undefined ? '' : String(m.pollMs),
    kept: {
        ...(m.bit === undefined ? {} : { bit: m.bit }),
        ...(m.ttlS === undefined ? {} : { ttlS: m.ttlS }),
    },
});

const toMapping = (r: Row): ModbusMapping => ({
    device: r.device,
    ...(r.table === 'holding' ? {} : { table: r.table }),
    address: r.address.trim() === '' ? Number.NaN : Number(r.address),
    ...(isBits(r.table) || r.type === 'uint16' ? {} : { type: r.type }),
    ...(!isBits(r.table) && isWide(r.type) && r.wordOrder === 'little'
        ? { wordOrder: 'little' as const }
        : {}),
    asset: r.asset,
    metric: r.metric,
    ...(r.scale.trim() ? { scale: Number(r.scale) } : {}),
    ...(r.offset.trim() ? { offset: Number(r.offset) } : {}),
    ...(r.pollMs.trim() ? { pollMs: Number(r.pollMs) } : {}),
    ...r.kept,
});

/**
 * Which registers and bits of the project's Modbus devices feed which asset
 * metrics. The twin's gateway polls them; saving makes a new version.
 */
export function ModbusPanel({
    twinId,
    definition,
    devices,
}: {
    twinId: string;
    definition: TwinDefinition;
    devices: string[];
}) {
    const [rows, setRows] = useState<Row[]>(() =>
        (definition.modbus ?? []).map(toRow),
    );
    const [errors, setErrors] = useState<string[]>([]);
    const [saving, setSaving] = useState(false);
    const assets = definition.assets.filter((a) => a.kind !== 'site');
    const saved = JSON.stringify((definition.modbus ?? []).map(toRow));
    const dirty = JSON.stringify(rows) !== saved;

    const change = (i: number, patch: Partial<Row>) =>
        setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));

    const metricsOf = (assetId: string) => {
        const asset = definition.assets.find((a) => a.id === assetId);
        return asset
            ? metricsFor(definition, asset).filter((m) => !m.hidden)
            : [];
    };

    const save = () => {
        const mappings = rows.map(toMapping);
        const next = { ...definition, modbus: mappings };
        const problems = validateDefinition(next);
        // A device the project no longer has is kept, and skipped by the gateway.
        setErrors(problems);
        if (problems.length > 0) return;
        setSaving(true);
        router.post(
            storeVersion.url(twinId),
            {
                definition: next as unknown as Record<string, never>,
                note: 'Mapped Modbus registers',
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <section aria-labelledby="modbus-mappings-heading">
            <h3 id="modbus-mappings-heading">Modbus</h3>
            <p className="muted hint">
                Registers and bits of the project's Modbus devices, polled by
                the gateway. Addresses count from 0: holding register 40001 is
                0. A 32- or 64-bit value spans the registers from its address;
                choose <em>Low word first</em> if the device sends it that way.
            </p>
            {devices.length === 0 && (
                <p className="muted">
                    Add a Modbus device on the project page to map its
                    registers.
                </p>
            )}
            {rows.length > 0 && (
                <ul className="editor-layers" aria-label="Modbus mappings">
                    {rows.map((row, i) => {
                        const metrics = metricsOf(row.asset);
                        const name = `${row.device} ${row.address || i + 1}`;
                        return (
                            <li key={i} className="telemetry-row">
                                <label>
                                    <span>Device</span>
                                    <select
                                        value={row.device}
                                        onChange={(e) =>
                                            change(i, {
                                                device: e.target.value,
                                            })
                                        }
                                    >
                                        {!devices.includes(row.device) && (
                                            <option value={row.device}>
                                                {row.device} (not in the
                                                project)
                                            </option>
                                        )}
                                        {devices.map((d) => (
                                            <option key={d} value={d}>
                                                {d}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label>
                                    <span>Table</span>
                                    <select
                                        value={row.table}
                                        onChange={(e) =>
                                            change(i, {
                                                table: e.target
                                                    .value as ModbusTable,
                                            })
                                        }
                                    >
                                        {TABLES.map((t) => (
                                            <option
                                                key={t.value}
                                                value={t.value}
                                            >
                                                {t.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label>
                                    <span>Address</span>
                                    <input
                                        inputMode="numeric"
                                        value={row.address}
                                        placeholder="0"
                                        onChange={(e) =>
                                            change(i, {
                                                address: e.target.value,
                                            })
                                        }
                                    />
                                </label>
                                {!isBits(row.table) && (
                                    <label>
                                        <span>Type</span>
                                        <select
                                            value={row.type}
                                            onChange={(e) =>
                                                change(i, {
                                                    type: e.target
                                                        .value as ModbusType,
                                                })
                                            }
                                        >
                                            {TYPES.map((t) => (
                                                <option
                                                    key={t.value}
                                                    value={t.value}
                                                >
                                                    {t.label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                )}
                                {!isBits(row.table) && isWide(row.type) && (
                                    <label>
                                        <span>Word order</span>
                                        <select
                                            value={row.wordOrder}
                                            onChange={(e) =>
                                                change(i, {
                                                    wordOrder: e.target
                                                        .value as
                                                        | 'big'
                                                        | 'little',
                                                })
                                            }
                                        >
                                            <option value="big">
                                                High word first
                                            </option>
                                            <option value="little">
                                                Low word first
                                            </option>
                                        </select>
                                    </label>
                                )}
                                <label>
                                    <span>Asset</span>
                                    <select
                                        value={row.asset}
                                        onChange={(e) =>
                                            change(i, {
                                                asset: e.target.value,
                                                metric:
                                                    metricsOf(e.target.value)[0]
                                                        ?.key ?? '',
                                            })
                                        }
                                    >
                                        {assets.map((a) => (
                                            <option key={a.id} value={a.id}>
                                                {a.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label>
                                    <span>Metric</span>
                                    <select
                                        value={row.metric}
                                        onChange={(e) =>
                                            change(i, {
                                                metric: e.target.value,
                                            })
                                        }
                                    >
                                        {metrics.map((m) => (
                                            <option key={m.key} value={m.key}>
                                                {m.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label>
                                    <span>Scale</span>
                                    <input
                                        inputMode="decimal"
                                        value={row.scale}
                                        placeholder="1"
                                        onChange={(e) =>
                                            change(i, { scale: e.target.value })
                                        }
                                    />
                                </label>
                                <label>
                                    <span>Offset</span>
                                    <input
                                        inputMode="decimal"
                                        value={row.offset}
                                        placeholder="0"
                                        onChange={(e) =>
                                            change(i, {
                                                offset: e.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <label>
                                    <span>Every (ms)</span>
                                    <input
                                        inputMode="numeric"
                                        value={row.pollMs}
                                        placeholder="1000"
                                        onChange={(e) =>
                                            change(i, {
                                                pollMs: e.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <button
                                    className="link"
                                    aria-label={`Remove mapping ${name}`}
                                    onClick={() =>
                                        setRows(rows.filter((_, j) => j !== i))
                                    }
                                >
                                    Remove
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
            <div className="editor-actions">
                <button
                    disabled={assets.length === 0 || devices.length === 0}
                    onClick={() => {
                        const asset = assets[0];
                        setRows([
                            ...rows,
                            {
                                device: devices[0],
                                table: 'holding',
                                address: '',
                                type: 'uint16',
                                wordOrder: 'big',
                                asset: asset.id,
                                metric: metricsOf(asset.id)[0]?.key ?? '',
                                scale: '',
                                offset: '',
                                pollMs: '',
                                kept: {},
                            },
                        ]);
                    }}
                >
                    Add register
                </button>
                <button
                    className="primary"
                    disabled={!dirty || saving}
                    onClick={save}
                >
                    Save Modbus mappings
                </button>
            </div>
            {errors.length > 0 && (
                <ul className="editor-error" role="alert">
                    {errors.map((error) => (
                        <li key={error}>{error}</li>
                    ))}
                </ul>
            )}
        </section>
    );
}

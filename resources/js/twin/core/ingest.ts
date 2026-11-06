import { metricsFor } from './metrics.ts';
import type { Reading } from './protocol.ts';
import type { TwinDefinition } from './types.ts';

export type IngestResult =
    | { ok: true; readings: Reading[] }
    | { ok: false; errors: string[] };

/** Validates an ingest payload against the twin, all-or-nothing. */
export function parseReadings(
    def: TwinDefinition,
    body: unknown,
): IngestResult {
    const list = Array.isArray(body) ? body : [body];
    if (list.length === 0) return { ok: false, errors: ['No readings'] };
    const assets = new Map(def.assets.map((a) => [a.id, a]));
    const errors: string[] = [];
    list.forEach((r: Partial<Reading> | null, i) => {
        const where = `reading ${i}`;
        if (typeof r !== 'object' || r === null) {
            errors.push(`${where}: not an object`);
            return;
        }
        const asset = assets.get(String(r.asset));
        if (!asset) errors.push(`${where}: unknown asset ${String(r.asset)}`);
        else if (
            !metricsFor(def, asset).some((m) => m.key === r.metric && !m.hidden)
        ) {
            errors.push(
                `${where}: ${asset.kind === 'custom' ? String(asset.type) : asset.kind} has no metric ${String(r.metric)}`,
            );
        }
        if (typeof r.value !== 'number' || !Number.isFinite(r.value))
            errors.push(`${where}: value must be a finite number`);
        if (
            r.ttlS !== undefined &&
            (typeof r.ttlS !== 'number' || !(r.ttlS > 0))
        )
            errors.push(`${where}: ttlS must be positive`);
    });
    return errors.length
        ? { ok: false, errors }
        : { ok: true, readings: list as Reading[] };
}

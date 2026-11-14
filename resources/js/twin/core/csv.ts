import { metricsFor } from './metrics.ts';
import type { TwinDefinition } from './types.ts';

/**
 * Reading the start of a CSV file of readings in the browser, to guess how
 * it's laid out before it's uploaded: what separates its columns, which holds
 * the time and how it's written, and whether each series is a column (wide)
 * or each row names its series (long). The control plane reads the whole
 * file the same way when it imports it.
 */

export type Delimiter = ',' | ';' | '\t' | '|';
export type TimeFormat = 'iso' | 'dmy' | 'mdy' | 'unix';

export const DELIMITERS: { value: Delimiter; label: string }[] = [
    { value: ',', label: 'Commas' },
    { value: ';', label: 'Semicolons' },
    { value: '\t', label: 'Tabs' },
    { value: '|', label: 'Bars' },
];

export const TIME_FORMATS: { value: TimeFormat; label: string }[] = [
    { value: 'iso', label: 'Year first: 2026-03-01 06:30' },
    { value: 'dmy', label: 'Day first: 01/03/2026 06:30' },
    { value: 'mdy', label: 'Month first: 03/01/2026 06:30' },
    { value: 'unix', label: 'Unix seconds or milliseconds' },
];

/** Rows of cells, honouring quotes ("a, b" and "" inside them). */
export function parseCsv(text: string, delimiter: Delimiter): string[][] {
    const rows: string[][] = [];
    let row: string[] = [];
    let cell = '';
    let quoted = false;
    for (let i = 0; i < text.length; i++) {
        const c = text[i];
        if (quoted) {
            if (c === '"' && text[i + 1] === '"') {
                cell += '"';
                i++;
            } else if (c === '"') {
                quoted = false;
            } else {
                cell += c;
            }
        } else if (c === '"' && cell === '') {
            quoted = true;
        } else if (c === delimiter) {
            row.push(cell);
            cell = '';
        } else if (c === '\n' || c === '\r') {
            if (c === '\r' && text[i + 1] === '\n') i++;
            row.push(cell);
            rows.push(row);
            row = [];
            cell = '';
        } else {
            cell += c;
        }
    }
    if (cell !== '' || row.length > 0) {
        row.push(cell);
        rows.push(row);
    }
    return rows
        .map((r) => r.map((value) => value.trim()))
        .filter((r) => r.some((value) => value !== ''));
}

const ISO =
    /^\d{4}-\d{2}-\d{2}([T ]\d{1,2}:\d{2}(:\d{2}([.,]\d{1,6})?)?)?\s*(Z|[+-]\d{2}(:?\d{2})?)?$/i;
const SLASHED =
    /^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4}|\d{2})([ T,]+\d{1,2}:\d{2}(:\d{2})?\s*(am|pm)?)?$/i;
const UNIX = /^\d{9,13}(\.\d+)?$/;

/** How a column of times is written, if it's one of the formats read. */
export function timeFormatOf(values: string[]): TimeFormat | null {
    const filled = values.filter((v) => v !== '');
    if (filled.length === 0) return null;
    if (filled.every((v) => ISO.test(v))) return 'iso';
    if (filled.every((v) => UNIX.test(v))) return 'unix';
    if (!filled.every((v) => SLASHED.test(v))) return null;
    // Day first unless a first part is over 12 and none of the second is.
    const parts = filled.map((v) => SLASHED.exec(v)!);
    const firstOver = parts.some((p) => Number(p[1]) > 12);
    const secondOver = parts.some((p) => Number(p[2]) > 12);
    return secondOver && !firstOver ? 'mdy' : 'dmy';
}

const isNumber = (value: string, delimiter: Delimiter) =>
    value !== '' &&
    Number.isFinite(
        Number(
            delimiter !== ',' && /^-?\d+,\d+$/.test(value)
                ? value.replace(',', '.')
                : value,
        ),
    );

export interface CsvSniff {
    delimiter: Delimiter;
    header: string[];
    /** The first rows after the header. */
    rows: string[][];
    layout: 'wide' | 'long';
    time: string;
    format: TimeFormat;
    /** For a long file: the columns naming the series and holding the value. */
    name: string;
    value: string;
}

/** Guesses how the start of a file is laid out. */
export function sniffCsv(text: string, delimiter?: Delimiter): CsvSniff {
    const start = text.replace(/^﻿/, '');
    const firstLine = start.split(/\r?\n/, 1)[0] ?? '';
    const chosen =
        delimiter ??
        ([...DELIMITERS]
            .map((d) => d.value)
            .sort(
                (a, b) => firstLine.split(b).length - firstLine.split(a).length,
            )[0] as Delimiter);
    const [header = [], ...rows] = parseCsv(start, chosen).slice(0, 201);
    const column = (i: number) => rows.map((r) => r[i] ?? '');

    // A time-like name whose values read as times, else values that read as
    // dates, else the name alone, else the first column.
    const named = (h: string) => /time|date|when|stamp/i.test(h);
    const dated = (i: number) => {
        const format = timeFormatOf(column(i));
        return format !== null && format !== 'unix';
    };
    let timeIndex = header.findIndex(
        (h, i) => named(h) && timeFormatOf(column(i)) !== null,
    );
    if (timeIndex < 0) timeIndex = header.findIndex((_, i) => dated(i));
    if (timeIndex < 0) timeIndex = Math.max(0, header.findIndex(named));
    const others = header.map((_, i) => i).filter((i) => i !== timeIndex);
    const numeric = others.filter((i) =>
        column(i).every((v) => v === '' || isNumber(v, chosen)),
    );
    // Long: exactly one numeric column, and a text column whose values repeat.
    const texts = others.filter((i) => !numeric.includes(i));
    const nameIndex = texts.find(
        (i) => new Set(column(i)).size < Math.max(2, rows.length),
    );
    const long = numeric.length === 1 && nameIndex !== undefined;

    return {
        delimiter: chosen,
        header,
        rows: rows.slice(0, 200),
        layout: long ? 'long' : 'wide',
        time: header[timeIndex] ?? '',
        format: timeFormatOf(column(timeIndex)) ?? 'iso',
        name: long ? header[nameIndex] : (header[texts[0] ?? others[0]] ?? ''),
        value: long ? header[numeric[0]] : (header[numeric[0]] ?? ''),
    };
}

/** The file's series: each column but the time (wide), or each name (long). */
export function seriesOf(sniff: CsvSniff): string[] {
    if (sniff.layout === 'wide')
        return sniff.header.filter((h) => h !== sniff.time && h !== '');
    const index = sniff.header.indexOf(sniff.name);
    return [...new Set(sniff.rows.map((r) => r[index] ?? '').filter(Boolean))];
}

export interface MetricChoice {
    /** `asset|metric`. */
    value: string;
    asset: string;
    metric: string;
    label: string;
}

/** Every metric of the twin's assets a reading can be imported to. */
export function metricChoices(def: TwinDefinition): MetricChoice[] {
    return def.assets
        .filter((a) => a.kind !== 'site')
        .flatMap((asset) =>
            metricsFor(def, asset)
                .filter((m) => !m.hidden)
                .map((m) => ({
                    value: `${asset.id}|${m.key}`,
                    asset: asset.id,
                    metric: m.key,
                    label: `${asset.name} · ${m.label}`,
                })),
        );
}

const simple = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, '');

/**
 * The metric a series is most likely meant for: one named `asset.metric`,
 * or `Asset name Metric label`, or an asset by name with a single metric.
 */
export function guessMetric(
    name: string,
    choices: MetricChoice[],
    def: TwinDefinition,
): string {
    const key = simple(name);
    const exact = choices.find(
        (c) =>
            simple(`${c.asset}.${c.metric}`) === key || simple(c.label) === key,
    );
    if (exact) return exact.value;
    const asset = def.assets.find(
        (a) => simple(a.id) === key || simple(a.name) === key,
    );
    const ofAsset = choices.filter((c) => c.asset === asset?.id);
    return ofAsset.length === 1 ? ofAsset[0].value : '';
}

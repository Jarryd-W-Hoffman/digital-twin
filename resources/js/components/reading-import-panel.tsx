import { router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import TwinReadingImportController from '@/actions/App/Http/Controllers/TwinReadingImportController';
import {
    DELIMITERS,
    TIME_FORMATS,
    guessMetric,
    metricChoices,
    seriesOf,
    sniffCsv,
    type CsvSniff,
    type Delimiter,
    type TimeFormat,
} from '@/twin/core/csv';
import type { TwinDefinition } from '@/twin/core/types';

export type ReadingImportSummary = {
    id: string;
    fileName: string;
    status: 'importing' | 'done' | 'failed';
    error: string | null;
    series: number;
    summary: {
        rows: number;
        stored: number;
        skipped: number;
        problems: string[];
        unmapped: string[];
    } | null;
    firstAt: string | null;
    lastAt: string | null;
    createdBy: string | null;
    createdAt: string;
};

const RELOAD = {
    preserveScroll: true,
    preserveState: true,
    only: ['readingImports'],
};

/** How much of the file is read in the browser to guess its layout. */
const SNIFF_BYTES = 2 * 1024 * 1024;

const day = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

/**
 * CSV files of readings imported into the twin's history, for sites where
 * readings arrive in files rather than through a gateway: survey
 * monitoring, lab results, a logger's download. The start of the file is
 * read here to guess its layout; the control plane imports it all.
 */
export function ReadingImportPanel({
    twinId,
    definition,
    imports,
}: {
    twinId: string;
    definition: TwinDefinition;
    imports: ReadingImportSummary[];
}) {
    const [file, setFile] = useState<File | null>(null);
    const [text, setText] = useState('');
    const [sniff, setSniff] = useState<CsvSniff | null>(null);
    const [targets, setTargets] = useState<Record<string, string>>({});
    const choices = useMemo(() => metricChoices(definition), [definition]);
    const form = useForm<{
        file: File | null;
        layout: 'wide' | 'long';
        delimiter: Delimiter;
        time: string;
        format: TimeFormat;
        name: string;
        value: string;
        series: { name: string; asset: string; metric: string }[];
    }>({
        file: null,
        layout: 'wide',
        delimiter: ',',
        time: '',
        format: 'iso',
        name: '',
        value: '',
        series: [],
    });

    // Importing happens on the queue; look again until it's done.
    const importing = imports.some((i) => i.status === 'importing');
    useEffect(() => {
        if (!importing) return;
        const timer = setInterval(() => router.reload(RELOAD), 3000);
        return () => clearInterval(timer);
    }, [importing]);

    const guess = (next: CsvSniff) => {
        setSniff(next);
        setTargets(
            Object.fromEntries(
                seriesOf(next).map((name) => [
                    name,
                    guessMetric(name, choices, definition),
                ]),
            ),
        );
    };

    const choose = async (chosen: File | undefined) => {
        form.clearErrors();
        setFile(chosen ?? null);
        if (!chosen) {
            setSniff(null);
            return;
        }
        const start = await chosen.slice(0, SNIFF_BYTES).text();
        // Drop a last line the slice may have cut short.
        const whole =
            chosen.size > SNIFF_BYTES
                ? start.slice(0, start.lastIndexOf('\n') + 1)
                : start;
        setText(whole);
        guess(sniffCsv(whole));
    };

    const change = (patch: Partial<CsvSniff>) => {
        if (!sniff) return;
        const next = { ...sniff, ...patch };
        setSniff(next);
        if (patch.layout !== undefined || patch.name !== undefined) guess(next);
    };

    const series = sniff ? seriesOf(sniff) : [];
    const mapped = series.filter((name) => targets[name]);
    const timeIndex = sniff ? sniff.header.indexOf(sniff.time) : -1;
    const firstTime = sniff?.rows[0]?.[timeIndex] ?? '';

    const reset = () => {
        setFile(null);
        setSniff(null);
        setTargets({});
        form.reset();
    };

    const submit = () => {
        if (!file || !sniff) return;
        form.transform(() => ({
            file,
            layout: sniff.layout,
            delimiter: sniff.delimiter,
            time: sniff.time,
            format: sniff.format,
            name: sniff.layout === 'long' ? sniff.name : '',
            value: sniff.layout === 'long' ? sniff.value : '',
            series: mapped.map((name) => {
                const choice = choices.find((c) => c.value === targets[name])!;
                return {
                    name,
                    asset: choice.asset,
                    metric: choice.metric,
                };
            }),
        }));
        form.submit(TwinReadingImportController.store(twinId), {
            ...RELOAD,
            forceFormData: true,
            onSuccess: reset,
        });
    };

    const errors = Object.entries(form.errors).map(([, message]) => message);

    return (
        <section aria-labelledby="reading-import-heading">
            <h3 id="reading-import-heading">Import readings</h3>
            <p className="muted hint">
                Readings from a CSV file, for data that arrives in files rather
                than through a gateway: survey monitoring, lab results, a
                logger's download. They go into the twin's history, for its
                charts, anomaly detection, reports and the API, in place of its
                readings of the same metrics over the file's time span. Times
                without an offset are the site's.
            </p>

            <label className="editor-field">
                <span>CSV file</span>
                <input
                    type="file"
                    accept=".csv,.tsv,.txt,text/csv"
                    onChange={(e) => void choose(e.target.files?.[0])}
                />
            </label>

            {sniff && (
                <div
                    className="editor-form"
                    aria-label="How the file is laid out"
                >
                    <div className="import-grid">
                        <label className="editor-field">
                            <span>Separated by</span>
                            <select
                                value={sniff.delimiter}
                                onChange={(e) =>
                                    guess(
                                        sniffCsv(
                                            text,
                                            e.target.value as Delimiter,
                                        ),
                                    )
                                }
                            >
                                {DELIMITERS.map((d) => (
                                    <option key={d.value} value={d.value}>
                                        {d.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="editor-field">
                            <span>Layout</span>
                            <select
                                value={sniff.layout}
                                onChange={(e) =>
                                    change({
                                        layout: e.target.value as
                                            | 'wide'
                                            | 'long',
                                    })
                                }
                            >
                                <option value="wide">
                                    A column per series
                                </option>
                                <option value="long">A row per reading</option>
                            </select>
                        </label>
                        <label className="editor-field">
                            <span>Time column</span>
                            <select
                                value={sniff.time}
                                onChange={(e) =>
                                    change({ time: e.target.value })
                                }
                            >
                                {sniff.header.map((h) => (
                                    <option key={h} value={h}>
                                        {h}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="editor-field">
                            <span>Times are written</span>
                            <select
                                value={sniff.format}
                                onChange={(e) =>
                                    change({
                                        format: e.target.value as TimeFormat,
                                    })
                                }
                            >
                                {TIME_FORMATS.map((f) => (
                                    <option key={f.value} value={f.value}>
                                        {f.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {sniff.layout === 'long' && (
                            <>
                                <label className="editor-field">
                                    <span>Series named in</span>
                                    <select
                                        value={sniff.name}
                                        onChange={(e) =>
                                            change({ name: e.target.value })
                                        }
                                    >
                                        {sniff.header.map((h) => (
                                            <option key={h} value={h}>
                                                {h}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="editor-field">
                                    <span>Values in</span>
                                    <select
                                        value={sniff.value}
                                        onChange={(e) =>
                                            change({ value: e.target.value })
                                        }
                                    >
                                        {sniff.header.map((h) => (
                                            <option key={h} value={h}>
                                                {h}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </>
                        )}
                    </div>
                    {firstTime && (
                        <p className="muted">First time: {firstTime}</p>
                    )}

                    <table className="import-series" aria-label="Series">
                        <thead>
                            <tr>
                                <th scope="col">
                                    {sniff.layout === 'wide'
                                        ? 'Column'
                                        : 'Series'}
                                </th>
                                <th scope="col">Metric</th>
                            </tr>
                        </thead>
                        <tbody>
                            {series.map((name) => (
                                <tr key={name}>
                                    <th scope="row">{name}</th>
                                    <td>
                                        <select
                                            aria-label={`Metric for ${name}`}
                                            value={targets[name] ?? ''}
                                            onChange={(e) =>
                                                setTargets({
                                                    ...targets,
                                                    [name]: e.target.value,
                                                })
                                            }
                                        >
                                            <option value="">Skip</option>
                                            {choices.map((c) => (
                                                <option
                                                    key={c.value}
                                                    value={c.value}
                                                >
                                                    {c.label}
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {sniff.layout === 'long' && (
                        <p className="muted">
                            Names found in the start of the file. Rows naming
                            others are skipped and listed after the import.
                        </p>
                    )}

                    {errors.length > 0 && (
                        <ul className="editor-error" role="alert">
                            {[...new Set(errors)].map((error) => (
                                <li key={error}>{error}</li>
                            ))}
                        </ul>
                    )}
                    <div className="editor-actions">
                        <button
                            className="primary"
                            disabled={form.processing || mapped.length === 0}
                            onClick={submit}
                        >
                            Import {mapped.length} series
                        </button>
                        <button className="ghost" onClick={reset}>
                            Cancel
                        </button>
                    </div>
                </div>
            )}

            {imports.length > 0 && (
                <ul className="view-list" aria-label="Imports">
                    {imports.map((i) => (
                        <li
                            key={i.id}
                            className="view-row"
                            aria-label={i.fileName}
                        >
                            <span className="view-name">{i.fileName}</span>
                            <span className="issue-meta">
                                {i.createdBy ?? 'Someone'} · {day(i.createdAt)}
                            </span>
                            {i.status === 'importing' && (
                                <p className="muted" role="status">
                                    Importing…
                                </p>
                            )}
                            {i.status === 'failed' && (
                                <p className="editor-error" role="alert">
                                    {i.error}
                                </p>
                            )}
                            {i.status === 'done' && i.summary && (
                                <>
                                    <p className="muted" role="status">
                                        {i.summary.stored.toLocaleString()}{' '}
                                        readings in {i.series} series
                                        {i.firstAt && i.lastAt
                                            ? `, ${day(i.firstAt)} to ${day(i.lastAt)}`
                                            : ''}
                                        {i.summary.skipped > 0
                                            ? `. ${i.summary.skipped.toLocaleString()} skipped.`
                                            : '.'}
                                    </p>
                                    {(i.summary.problems.length > 0 ||
                                        i.summary.unmapped.length > 0) && (
                                        <details>
                                            <summary>
                                                Why some were skipped
                                            </summary>
                                            <ul className="muted">
                                                {i.summary.unmapped.length >
                                                    0 && (
                                                    <li>
                                                        Not mapped to a metric:{' '}
                                                        {i.summary.unmapped.join(
                                                            ', ',
                                                        )}
                                                    </li>
                                                )}
                                                {i.summary.problems.map((p) => (
                                                    <li key={p}>{p}</li>
                                                ))}
                                            </ul>
                                        </details>
                                    )}
                                </>
                            )}
                            <div className="view-actions">
                                <button
                                    className="ghost"
                                    disabled={i.status === 'importing'}
                                    onClick={() => {
                                        if (
                                            !confirm(
                                                i.status === 'done'
                                                    ? `Remove ${i.fileName} and the readings it imported?`
                                                    : `Remove ${i.fileName}?`,
                                            )
                                        )
                                            return;
                                        router.delete(
                                            TwinReadingImportController.destroy.url(
                                                [twinId, i.id],
                                            ),
                                            RELOAD,
                                        );
                                    }}
                                >
                                    Remove
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

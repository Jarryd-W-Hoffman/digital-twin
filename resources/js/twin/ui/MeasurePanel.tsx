import { useEffect } from 'react';
import {
    formatArea,
    formatDegrees,
    formatLength,
    formatSlope,
    type MeasureLock,
    type MeasureTool,
} from '../core/measure.ts';
import { useTwin } from '../state/store.ts';

const TOOLS: { tool: MeasureTool; label: string; hint: string }[] = [
    {
        tool: 'distance',
        label: 'Distance',
        hint: 'Click two points. The legs show how far along the level and how far up.',
    },
    {
        tool: 'height',
        label: 'Height',
        hint: 'Click a roof, a tank or anything else to see how high it is above the ground.',
    },
    {
        tool: 'area',
        label: 'Area',
        hint: 'Click around the area. It closes itself; Clear starts another.',
    },
    {
        tool: 'point',
        label: 'Point',
        hint: 'Click a point to see where it is, how high, and how steep the ground is there.',
    },
];

const LOCKS: { lock: MeasureLock; label: string }[] = [
    { lock: 'none', label: 'Free' },
    { lock: 'horizontal', label: 'Level' },
    { lock: 'vertical', label: 'Vertical' },
];

/**
 * The measuring tools: choose one, then click in the scene. Measurements
 * aren't saved; they're for answering a question about the site.
 */
export function MeasurePanel() {
    const measuring = useTwin((s) => s.measuring);
    const measurement = useTwin((s) => s.measurement);
    const { setMeasuring, clearMeasurement } = useTwin.getState();

    useEffect(() => {
        if (!measuring) return;
        const stop = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setMeasuring(null);
        };
        window.addEventListener('keydown', stop);
        return () => window.removeEventListener('keydown', stop);
    }, [measuring, setMeasuring]);

    if (!measuring) return null;
    const current = TOOLS.find((t) => t.tool === measuring.tool)!;
    const rows: [string, string][] = [];
    if (measurement?.tool === 'distance') {
        rows.push(['Distance', formatLength(measurement.slant)]);
        rows.push(['Along the level', formatLength(measurement.horizontal)]);
        rows.push([
            // Under 5 mm either way reads as level, not as "down 0.00 m".
            measurement.vertical > -0.005 ? 'Up' : 'Down',
            formatLength(Math.abs(measurement.vertical)),
        ]);
    } else if (measurement?.tool === 'height') {
        rows.push(['Above the ground', formatLength(measurement.aboveGround)]);
    } else if (measurement?.tool === 'area') {
        rows.push(['Area', formatArea(measurement.area)]);
        rows.push(['Perimeter', formatLength(measurement.perimeter)]);
    } else if (measurement?.tool === 'point') {
        const [p] = measurement.points;
        rows.push(['Latitude', formatDegrees(p!.lat)]);
        rows.push(['Longitude', formatDegrees(p!.lon)]);
        rows.push(['Height', `${formatLength(p!.height)} above the ellipsoid`]);
        if (measurement.aboveGround !== null)
            rows.push([
                'Above the ground',
                formatLength(measurement.aboveGround),
            ]);
        if (measurement.slope !== null)
            rows.push([
                'Ground slope',
                formatSlope(measurement.slope, measurement.aspect),
            ]);
    }

    return (
        <section className="tool-panel" aria-label="Measure">
            <div className="segmented" role="group" aria-label="Measuring tool">
                {TOOLS.map((t) => (
                    <button
                        key={t.tool}
                        aria-pressed={measuring.tool === t.tool}
                        className={measuring.tool === t.tool ? 'on' : ''}
                        onClick={() =>
                            setMeasuring({ tool: t.tool, lock: measuring.lock })
                        }
                    >
                        {t.label}
                    </button>
                ))}
            </div>
            {measuring.tool === 'distance' && (
                <div
                    className="segmented"
                    role="group"
                    aria-label="Second point"
                >
                    {LOCKS.map((l) => (
                        <button
                            key={l.lock}
                            aria-pressed={measuring.lock === l.lock}
                            className={measuring.lock === l.lock ? 'on' : ''}
                            title={
                                l.lock === 'horizontal'
                                    ? 'Keep the second point level with the first'
                                    : l.lock === 'vertical'
                                      ? 'Keep the second point straight above or below the first'
                                      : 'Measure to whatever is under the pointer'
                            }
                            onClick={() =>
                                setMeasuring({ ...measuring, lock: l.lock })
                            }
                        >
                            {l.label}
                        </button>
                    ))}
                </div>
            )}
            {rows.length > 0 ? (
                <dl className="measure-readout" aria-label="Measurement">
                    {rows.map(([label, value]) => (
                        <div key={label}>
                            <dt>{label}</dt>
                            <dd>{value}</dd>
                        </div>
                    ))}
                </dl>
            ) : (
                <p className="muted hint">{current.hint}</p>
            )}
            <div className="tool-actions">
                <button onClick={clearMeasurement} disabled={!measurement}>
                    Clear
                </button>
                <button onClick={() => setMeasuring(null)}>Done</button>
            </div>
        </section>
    );
}

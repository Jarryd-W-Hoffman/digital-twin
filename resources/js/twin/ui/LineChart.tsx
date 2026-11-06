import { useState } from 'react';
import { useWidth } from './hooks.ts';

export interface Series {
    key: string;
    label: string;
    color: string;
    points: { t: number; v: number }[];
}

interface Props {
    series: Series[];
    height?: number;
    /** Horizontal reference lines, such as a temperature limit. */
    references?: { value: number; label: string }[];
    formatValue: (v: number) => string;
    formatTime: (t: number) => string;
    /** Marks a moment on the chart, such as the time-travel position. */
    marker?: number;
    axes?: boolean;
    label: string;
}

const PAD = { top: 8, right: 8, bottom: 18, left: 56 };
const SPARK_PAD = { top: 4, right: 2, bottom: 4, left: 2 };

/** A thin line chart with a snapping crosshair and one tooltip for every series. */
export function LineChart({
    series,
    height = 120,
    references = [],
    formatValue,
    formatTime,
    marker,
    axes = true,
    label,
}: Props) {
    const [ref, width] = useWidth<HTMLDivElement>();
    const [hover, setHover] = useState<number | null>(null);
    const pad = axes ? PAD : SPARK_PAD;
    const all = series.flatMap((s) => s.points);
    const ts = all.map((p) => p.t);
    const vs = [...all.map((p) => p.v), ...references.map((r) => r.value)];

    if (all.length < 2 || width === 0) {
        return (
            <div
                ref={ref}
                className="chart"
                style={{ height }}
                role="img"
                aria-label={`${label}: not enough data yet`}
            />
        );
    }

    const t0 = Math.min(...ts);
    const t1 = Math.max(...ts);
    let v0 = Math.min(...vs);
    let v1 = Math.max(...vs);
    const margin = (v1 - v0) * 0.1 || Math.abs(v1) * 0.1 || 1;
    v0 -= margin;
    v1 += margin;
    const x = (t: number) =>
        pad.left + ((t - t0) / (t1 - t0 || 1)) * (width - pad.left - pad.right);
    const y = (v: number) =>
        height -
        pad.bottom -
        ((v - v0) / (v1 - v0)) * (height - pad.top - pad.bottom);

    const longest = series.reduce((a, b) =>
        b.points.length > a.points.length ? b : a,
    );
    const snapped = hover === null ? null : nearest(longest.points, hover);
    const flip = snapped !== null && x(snapped) > width * 0.6;

    return (
        <div
            ref={ref}
            className="chart"
            style={{ height }}
            onPointerMove={(e) => {
                const rect = e.currentTarget.getBoundingClientRect();
                const f =
                    (e.clientX - rect.left - pad.left) /
                    (width - pad.left - pad.right);
                setHover(t0 + Math.min(1, Math.max(0, f)) * (t1 - t0));
            }}
            onPointerLeave={() => setHover(null)}
        >
            <svg width={width} height={height} role="img" aria-label={label}>
                {axes && (
                    <g className="chart-axis">
                        {[v0 + margin, (v0 + v1) / 2, v1 - margin].map((v) => (
                            <g key={v}>
                                <line
                                    x1={pad.left}
                                    x2={width - pad.right}
                                    y1={y(v)}
                                    y2={y(v)}
                                />
                                <text
                                    x={pad.left - 6}
                                    y={y(v) + 3}
                                    textAnchor="end"
                                >
                                    {formatValue(v)}
                                </text>
                            </g>
                        ))}
                        <text x={pad.left} y={height - 4}>
                            {formatTime(t0)}
                        </text>
                        <text
                            x={width - pad.right}
                            y={height - 4}
                            textAnchor="end"
                        >
                            {formatTime(t1)}
                        </text>
                    </g>
                )}
                {references.map((r) => (
                    <g key={r.label} className="chart-reference">
                        <line
                            x1={pad.left}
                            x2={width - pad.right}
                            y1={y(r.value)}
                            y2={y(r.value)}
                        />
                        {axes && (
                            <text
                                x={width - pad.right}
                                y={y(r.value) - 4}
                                textAnchor="end"
                            >
                                {r.label}
                            </text>
                        )}
                    </g>
                ))}
                {series.map((s) => (
                    <path
                        key={s.key}
                        d={linePath(s.points, x, y)}
                        stroke={s.color}
                        className="chart-line"
                    />
                ))}
                {marker !== undefined && marker >= t0 && marker <= t1 && (
                    <line
                        className="chart-marker"
                        x1={x(marker)}
                        x2={x(marker)}
                        y1={pad.top}
                        y2={height - pad.bottom}
                    />
                )}
                {snapped !== null && (
                    <g>
                        <line
                            className="chart-crosshair"
                            x1={x(snapped)}
                            x2={x(snapped)}
                            y1={pad.top}
                            y2={height - pad.bottom}
                        />
                        {series.map((s) => {
                            const v = valueAt(s.points, snapped);
                            return v === undefined ? null : (
                                <circle
                                    key={s.key}
                                    cx={x(snapped)}
                                    cy={y(v)}
                                    r={3}
                                    fill={s.color}
                                    className="chart-dot"
                                />
                            );
                        })}
                    </g>
                )}
            </svg>
            {snapped !== null && (
                <div
                    className="chart-tooltip"
                    style={
                        flip
                            ? { right: width - x(snapped) + 8 }
                            : { left: x(snapped) + 8 }
                    }
                >
                    <div className="chart-tooltip-time">
                        {formatTime(snapped)}
                    </div>
                    {series.map((s) => {
                        const v = valueAt(s.points, snapped);
                        return (
                            <div key={s.key} className="chart-tooltip-row">
                                <span
                                    className="chart-key"
                                    style={{ background: s.color }}
                                />
                                <strong>
                                    {v === undefined ? '—' : formatValue(v)}
                                </strong>
                                {series.length > 1 && <span>{s.label}</span>}
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function linePath(
    points: Series['points'],
    x: (t: number) => number,
    y: (v: number) => number,
): string {
    return points
        .map(
            (p, i) =>
                `${i ? 'L' : 'M'}${x(p.t).toFixed(1)} ${y(p.v).toFixed(1)}`,
        )
        .join('');
}

function nearest(points: Series['points'], t: number): number {
    let best = points[0]!.t;
    for (const p of points)
        if (Math.abs(p.t - t) < Math.abs(best - t)) best = p.t;
    return best;
}

function valueAt(points: Series['points'], t: number): number | undefined {
    return points.find((p) => p.t === t)?.v;
}

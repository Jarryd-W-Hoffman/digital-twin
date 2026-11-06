import { useMemo, useState } from 'react';
import { SPEEDS } from '../core/protocol.ts';
import { SEVERITY_COLOR } from '../scene/colors.ts';
import { getHistory, useTwin } from '../state/store.ts';
import { clock, kw } from './format.ts';
import { useWidth } from './hooks.ts';

const HEIGHT = 44;
const LINE = '#3987e5';

const speedLabel = (s: number) => (s === 0 ? 'Paused' : `${s}×`);

/** Time travel: the load trace with alert ticks, scrubbed with a native range input. */
export function Timeline() {
    const def = useTwin((s) => s.def);
    const live = useTwin((s) => s.live);
    const view = useTwin((s) => s.view);
    const replayT = useTwin((s) => s.replayT);
    const alerts = useTwin((s) => s.alerts);
    const speed = useTwin((s) => s.speed);
    const setSpeed = useTwin((s) => s.setSpeed);
    const historyVersion = useTwin((s) => s.historyVersion);
    const scrubTo = useTwin((s) => s.scrubTo);
    const goLive = useTwin((s) => s.goLive);
    const [ref, width] = useWidth<HTMLDivElement>();
    const [hover, setHover] = useState<{ x: number; t: number } | null>(null);
    const [lastRunning, setLastRunning] = useState(60);

    const points = useMemo(
        () => getHistory().series('site.loadKw', -Infinity, Infinity, 300),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [historyVersion],
    );
    if (!def) return <div className="panel timeline" />;

    const t0 = getHistory().first()?.t ?? 0;
    const t1 = Math.max(live?.t ?? 0, getHistory().last()?.t ?? 0);
    const span = t1 - t0 || 1;
    const x = (t: number) => ((t - t0) / span) * width;
    const peak = Math.max(1, ...points.map((p) => p.v));
    const y = (v: number) => HEIGHT - 3 - (v / peak) * (HEIGHT - 8);
    const path = points
        .map(
            (p, i) =>
                `${i ? 'L' : 'M'}${x(p.t).toFixed(1)} ${y(p.v).toFixed(1)}`,
        )
        .join('');
    const ticks = alerts.filter((a) => a.raisedAt >= t0);
    const position = view?.t ?? t1;
    const hoverValue = hover
        ? getHistory().nearest(hover.t)?.values['site.loadKw']
        : undefined;

    return (
        <footer className="panel timeline" aria-label="Timeline">
            <div className="timeline-controls">
                <button
                    className="play"
                    aria-label={speed === 0 ? 'Resume' : 'Pause'}
                    onClick={() => {
                        if (speed === 0) setSpeed(lastRunning);
                        else {
                            setLastRunning(speed);
                            setSpeed(0);
                        }
                    }}
                >
                    {speed === 0 ? '▶' : '❚❚'}
                </button>
                <div
                    className="segmented"
                    role="radiogroup"
                    aria-label="Simulation speed"
                >
                    {SPEEDS.filter((s) => s > 0).map((s) => (
                        <button
                            key={s}
                            role="radio"
                            aria-checked={speed === s}
                            className={speed === s ? 'on' : ''}
                            onClick={() => setSpeed(s)}
                        >
                            {speedLabel(s)}
                        </button>
                    ))}
                </div>
                <button
                    className={`live ${replayT === null ? 'is-live' : ''}`}
                    onClick={goLive}
                    disabled={replayT === null}
                >
                    <span aria-hidden="true">●</span>{' '}
                    {replayT === null ? 'Live' : 'Back to live'}
                </button>
            </div>
            <div
                ref={ref}
                className="timeline-track"
                onPointerMove={(e) => {
                    const rect = e.currentTarget.getBoundingClientRect();
                    const px = Math.min(
                        rect.width,
                        Math.max(0, e.clientX - rect.left),
                    );
                    setHover({ x: px, t: t0 + (px / rect.width) * span });
                }}
                onPointerLeave={() => setHover(null)}
            >
                {width > 0 && (
                    <svg width={width} height={HEIGHT} aria-hidden="true">
                        <path d={path} stroke={LINE} className="chart-line" />
                        <rect
                            x={x(position)}
                            y={0}
                            width={Math.max(0, width - x(position))}
                            height={HEIGHT}
                            className="timeline-future"
                        />
                        {ticks.map((a) => (
                            <rect
                                key={a.id}
                                x={x(a.raisedAt) - 1}
                                y={2}
                                width={2.5}
                                height={HEIGHT - 4}
                                rx={1}
                                fill={SEVERITY_COLOR[a.severity]}
                            />
                        ))}
                        <line
                            x1={x(position)}
                            x2={x(position)}
                            y1={0}
                            y2={HEIGHT}
                            className="timeline-head"
                        />
                    </svg>
                )}
                <input
                    type="range"
                    aria-label="Time"
                    aria-valuetext={clock(position, def.utcOffsetHours)}
                    min={t0}
                    max={t1}
                    step={60_000}
                    value={position}
                    onChange={(e) => {
                        const t = Number(e.target.value);
                        if (t >= t1 - 60_000) goLive();
                        else scrubTo(t);
                    }}
                />
                {hover && (
                    <div
                        className="chart-tooltip timeline-tooltip"
                        style={
                            hover.x > width * 0.7
                                ? { right: width - hover.x + 8 }
                                : { left: hover.x + 8 }
                        }
                    >
                        <div className="chart-tooltip-time">
                            {clock(hover.t, def.utcOffsetHours)}
                        </div>
                        <div className="chart-tooltip-row">
                            <strong>{kw(hoverValue)}</strong>
                            <span>Site load</span>
                        </div>
                    </div>
                )}
                <div className="timeline-axis">
                    <span>{clock(t0, def.utcOffsetHours)}</span>
                    <span>{clock(t1, def.utcOffsetHours)}</span>
                </div>
            </div>
        </footer>
    );
}

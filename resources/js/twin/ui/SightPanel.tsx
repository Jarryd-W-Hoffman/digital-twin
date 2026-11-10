import type { ReactNode } from 'react';
import { formatLength } from '../core/measure.ts';
import { formatHeading, LIMITS, type SensorShape } from '../core/sight.ts';
import { NEW_SIGHTING, type SensorSettings, useTwin } from '../state/store.ts';

const SHAPES: { shape: SensorShape; label: string }[] = [
    { shape: 'cone', label: 'Cone' },
    { shape: 'rectangle', label: 'Rectangle' },
    { shape: 'dome', label: 'Dome' },
];

function Slider({
    label,
    min,
    max,
    step = 1,
    value,
    shown,
    onChange,
}: {
    label: string;
    min: number;
    max: number;
    step?: number;
    value: number;
    shown: ReactNode;
    onChange: (value: number) => void;
}) {
    return (
        <label className="sight-slider">
            <span>{label}</span>
            <input
                type="range"
                aria-label={label}
                min={min}
                max={max}
                step={step}
                value={value}
                onChange={(e) => onChange(Number(e.target.value))}
            />
            <output>{shown}</output>
        </label>
    );
}

/**
 * What can be seen from a point. A viewshed tints everything a sensor of
 * the chosen shape can see green and what's hidden from it red; a line of
 * sight says whether one point can see another, and what's in the way.
 */
export function SightPanel() {
    const sighting = useTwin((s) => s.sighting);
    const { setSighting } = useTwin.getState();
    if (!sighting) return null;
    const { tool, settings, placed, next, line } = sighting;
    const change = (over: Partial<SensorSettings>) =>
        setSighting({ ...sighting, settings: { ...settings, ...over } });
    const degrees = (v: number) => `${Math.round(v)}°`;

    return (
        <section className="tool-panel" aria-label="Sight">
            <div className="segmented" role="group" aria-label="Sight tool">
                {(
                    [
                        ['viewshed', 'Viewshed'],
                        ['line', 'Line of sight'],
                    ] as const
                ).map(([value, label]) => (
                    <button
                        key={value}
                        aria-pressed={tool === value}
                        className={tool === value ? 'on' : ''}
                        onClick={() =>
                            setSighting({
                                ...sighting,
                                tool: value,
                                next:
                                    value === 'viewshed' && !placed
                                        ? 'place'
                                        : null,
                            })
                        }
                    >
                        {label}
                    </button>
                ))}
            </div>

            {tool === 'viewshed' && (
                <>
                    <div
                        className="segmented"
                        role="group"
                        aria-label="Sensor shape"
                    >
                        {SHAPES.map((s) => (
                            <button
                                key={s.shape}
                                aria-pressed={settings.shape === s.shape}
                                className={
                                    settings.shape === s.shape ? 'on' : ''
                                }
                                onClick={() => change({ shape: s.shape })}
                            >
                                {s.label}
                            </button>
                        ))}
                    </div>
                    {next === 'place' ? (
                        <p className="muted hint">
                            Click where the sensor goes: the ground, a roof or
                            the top of a mast.
                        </p>
                    ) : next === 'aim' ? (
                        <p className="muted hint">
                            Click what it should look at.
                        </p>
                    ) : (
                        <p className="sight-legend">
                            <span className="sight-seen">Seen</span>
                            <span className="sight-hidden">Hidden</span>
                            {settings.shape === 'dome'
                                ? 'all round'
                                : `facing ${formatHeading(settings.headingDeg)}`}
                        </p>
                    )}
                    <Slider
                        label="Sensor height"
                        min={0}
                        max={50}
                        step={0.5}
                        value={settings.height}
                        shown={formatLength(settings.height)}
                        onChange={(height) => change({ height })}
                    />
                    <Slider
                        label="Range"
                        min={LIMITS.range[0]}
                        max={LIMITS.range[1]}
                        step={5}
                        value={settings.range}
                        shown={formatLength(settings.range)}
                        onChange={(range) => change({ range })}
                    />
                    {settings.shape !== 'dome' && (
                        <>
                            <Slider
                                label="Heading"
                                min={0}
                                max={359}
                                value={settings.headingDeg}
                                shown={formatHeading(settings.headingDeg)}
                                onChange={(headingDeg) =>
                                    change({ headingDeg })
                                }
                            />
                            <Slider
                                label="Tilt"
                                min={LIMITS.tiltDeg[0]}
                                max={LIMITS.tiltDeg[1]}
                                value={settings.tiltDeg}
                                shown={degrees(settings.tiltDeg)}
                                onChange={(tiltDeg) => change({ tiltDeg })}
                            />
                        </>
                    )}
                    {settings.shape === 'cone' && (
                        <Slider
                            label="Cone angle"
                            min={LIMITS.coneDeg[0]}
                            max={LIMITS.coneDeg[1]}
                            value={settings.coneDeg}
                            shown={degrees(settings.coneDeg)}
                            onChange={(coneDeg) => change({ coneDeg })}
                        />
                    )}
                    {settings.shape === 'rectangle' && (
                        <>
                            <Slider
                                label="Angle across"
                                min={LIMITS.widthDeg[0]}
                                max={LIMITS.widthDeg[1]}
                                value={settings.widthDeg}
                                shown={degrees(settings.widthDeg)}
                                onChange={(widthDeg) => change({ widthDeg })}
                            />
                            <Slider
                                label="Angle up and down"
                                min={LIMITS.heightDeg[0]}
                                max={LIMITS.heightDeg[1]}
                                value={settings.heightDeg}
                                shown={degrees(settings.heightDeg)}
                                onChange={(heightDeg) => change({ heightDeg })}
                            />
                        </>
                    )}
                    <div className="tool-actions">
                        <button
                            aria-pressed={next === 'place'}
                            className={next === 'place' ? 'primary' : ''}
                            onClick={() =>
                                setSighting({
                                    ...sighting,
                                    next: next === 'place' ? null : 'place',
                                })
                            }
                            disabled={next === 'place' && !placed}
                        >
                            {placed ? 'Move sensor' : 'Place sensor'}
                        </button>
                        {settings.shape !== 'dome' && (
                            <button
                                aria-pressed={next === 'aim'}
                                className={next === 'aim' ? 'primary' : ''}
                                disabled={!placed}
                                onClick={() =>
                                    setSighting({
                                        ...sighting,
                                        next: next === 'aim' ? null : 'aim',
                                    })
                                }
                            >
                                Aim at…
                            </button>
                        )}
                    </div>
                </>
            )}

            {tool === 'line' && (
                <>
                    <Slider
                        label="Eye height"
                        min={0}
                        max={30}
                        step={0.1}
                        value={line.eye}
                        shown={formatLength(line.eye)}
                        onChange={(eye) =>
                            setSighting({
                                ...sighting,
                                line: { ...line, eye, result: null },
                            })
                        }
                    />
                    <Slider
                        label="Target height"
                        min={0}
                        max={30}
                        step={0.1}
                        value={line.target}
                        shown={formatLength(line.target)}
                        onChange={(target) =>
                            setSighting({
                                ...sighting,
                                line: { ...line, target, result: null },
                            })
                        }
                    />
                    {line.result ? (
                        <dl
                            className="measure-readout"
                            aria-label="Line of sight"
                        >
                            <div>
                                <dt>Can see it</dt>
                                <dd
                                    className={
                                        line.result.clear
                                            ? 'sight-seen'
                                            : 'sight-hidden'
                                    }
                                >
                                    {line.result.clear ? 'Yes' : 'No'}
                                </dd>
                            </div>
                            <div>
                                <dt>Distance</dt>
                                <dd>{formatLength(line.result.distance)}</dd>
                            </div>
                            {!line.result.clear && (
                                <div>
                                    <dt>Blocked at</dt>
                                    <dd>
                                        {formatLength(line.result.blockedAt)}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    ) : (
                        <p className="muted hint">
                            {line.from
                                ? 'Click what it should see.'
                                : 'Click where the observer stands, then what they should see.'}
                        </p>
                    )}
                </>
            )}

            <div className="tool-actions">
                <button
                    onClick={() =>
                        setSighting({
                            ...NEW_SIGHTING,
                            tool,
                            next: tool === 'viewshed' ? 'place' : null,
                        })
                    }
                >
                    Clear
                </button>
                <button onClick={() => setSighting(null)}>Done</button>
            </div>
        </section>
    );
}

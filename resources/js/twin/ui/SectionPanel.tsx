import { useMemo } from 'react';
import { reach } from '../core/section.ts';
import type { Point } from '../core/types.ts';
import { useTwin } from '../state/store.ts';

const COMPASS = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
const compass = (heading: number) => COMPASS[Math.round(heading / 45) % 8];

/** Where a new cut across starts: through the origin, taking away the north. */
const ACROSS = { offset: 0, heading: 0 };
/** Where a new cut above starts: about two storeys up. */
const ABOVE = 6;
/** The highest cut above, in metres. */
const HIGHEST = 60;

/**
 * Cutting the site open: everything above a height, everything on one side
 * of a vertical cut, or both. Tilesets and models are clipped, so the inside
 * of a building model shows; the blocks for assets are cut to match.
 */
export function SectionPanel() {
    const section = useTwin((s) => s.section);
    const def = useTwin((s) => s.def);
    const { setSection } = useTwin.getState();
    const range = useMemo(
        () =>
            reach(
                (def?.assets ?? []).flatMap(
                    (a): Point[] =>
                        a.footprint ?? (a.position ? [a.position] : []),
                ),
            ),
        [def],
    );

    if (!section) return null;
    const { above, across } = section;
    return (
        <section className="tool-panel section-panel" aria-label="Section">
            <label className="section-row">
                <input
                    type="checkbox"
                    checked={above !== null}
                    onChange={(e) =>
                        setSection({
                            ...section,
                            above: e.target.checked ? ABOVE : null,
                        })
                    }
                />
                Cut above
            </label>
            {above !== null && (
                <div className="section-slider">
                    <input
                        type="range"
                        aria-label="Cut height"
                        min={0}
                        max={HIGHEST}
                        step={0.5}
                        value={above}
                        onChange={(e) =>
                            setSection({
                                ...section,
                                above: Number(e.target.value),
                            })
                        }
                    />
                    <output>{above.toFixed(1)} m up</output>
                </div>
            )}
            <label className="section-row">
                <input
                    type="checkbox"
                    checked={across !== null}
                    onChange={(e) =>
                        setSection({
                            ...section,
                            across: e.target.checked ? ACROSS : null,
                        })
                    }
                />
                Cut across
            </label>
            {across !== null && (
                <>
                    <div className="section-slider">
                        <input
                            type="range"
                            aria-label="Cut position"
                            min={-range}
                            max={range}
                            step={1}
                            value={across.offset}
                            onChange={(e) =>
                                setSection({
                                    ...section,
                                    across: {
                                        ...across,
                                        offset: Number(e.target.value),
                                    },
                                })
                            }
                        />
                        <output>
                            {across.offset} m {compass(across.heading)}
                        </output>
                    </div>
                    <div className="section-slider">
                        <input
                            type="range"
                            aria-label="Cut direction"
                            min={0}
                            max={359}
                            step={1}
                            value={across.heading}
                            onChange={(e) =>
                                setSection({
                                    ...section,
                                    across: {
                                        ...across,
                                        heading: Number(e.target.value),
                                    },
                                })
                            }
                        />
                        <output>
                            takes away {compass(across.heading)} ·{' '}
                            {across.heading}°
                        </output>
                    </div>
                </>
            )}
            <p className="muted hint">
                Clipped models and tilesets show their insides; the ground isn't
                cut.
            </p>
            <div className="tool-actions">
                {across !== null && (
                    <button
                        title="Keep the other side of the cut instead"
                        onClick={() =>
                            setSection({
                                ...section,
                                across: {
                                    offset: -across.offset,
                                    heading: (across.heading + 180) % 360,
                                },
                            })
                        }
                    >
                        Flip
                    </button>
                )}
                <button onClick={() => setSection(null)}>Done</button>
            </div>
        </section>
    );
}

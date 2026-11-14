import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import TwinSurveyComparisonController from '@/actions/App/Http/Controllers/TwinSurveyComparisonController';
import { useSketch } from '@/components/definition-editor';
import type { TwinAssetSummary } from '@/components/twin-editor';
import { lonLatToMetres, metresToLonLat } from '@/twin/core/geo';
import type { TwinDefinition } from '@/twin/core/types';
import { useTwin } from '@/twin/state/store';

/** The change between a survey and the one before it, inside the area. */
export type SurveyChange = {
    before: string;
    after: string;
    cut: number;
    fill: number;
    net: number;
    areaM2: number;
    coveredM2: number;
    deepestCut: number;
    highestFill: number;
    bounds: [number, number, number, number];
    image: string;
};

export type SurveyComparisonSummary = {
    id: string;
    name: string;
    /** Longitude and latitude. */
    area: [number, number][];
    /** Survey ids, oldest first. */
    surveys: string[];
    status: 'measuring' | 'ready' | 'failed';
    error: string | null;
    measuredAt: string | null;
    results: SurveyChange[];
};

const RELOAD = {
    preserveScroll: true,
    preserveState: true,
    only: ['comparisons'],
};

/** A volume to the cubic metre, with a sign (and a true minus) when asked. */
const volume = (m3: number, signed = false) => {
    const whole = Math.round(m3);
    const sign = whole < 0 ? '−' : signed && whole > 0 ? '+' : '';
    return `${sign}${Math.abs(whole).toLocaleString()} m³`;
};

/**
 * Areas where surveys of the ground are compared: the volume cut and filled
 * between each survey and the one before, and the running total since the
 * first. Everyone sees them and can lay a change over the scene; editors
 * draw the area and choose the surveys.
 */
export function TwinVolumes({
    twinId,
    definition,
    assets,
    comparisons,
    canEdit,
    onClose,
}: {
    twinId: string;
    definition: TwinDefinition;
    assets: TwinAssetSummary[];
    comparisons: SurveyComparisonSummary[];
    canEdit: boolean;
    onClose: () => void;
}) {
    const surveys = assets.filter((a) => a.survey);
    const name = (id: string) =>
        assets.find((a) => a.id === id)?.name ?? 'A deleted survey';
    const overlay = useTwin((s) => s.surveyOverlay);
    const [editing, setEditing] = useState<
        SurveyComparisonSummary | 'new' | null
    >(null);

    // Measuring happens on the converter; look again until it's done.
    const measuring = comparisons.some((c) => c.status === 'measuring');
    useEffect(() => {
        if (!measuring) return;
        const timer = setInterval(() => router.reload(RELOAD), 5000);
        return () => clearInterval(timer);
    }, [measuring]);

    // Leaving the panel takes the change off the scene.
    useEffect(() => () => useTwin.getState().setSurveyOverlay(null), []);

    const show = (comparison: SurveyComparisonSummary, change: SurveyChange) =>
        useTwin.getState().setSurveyOverlay(
            overlay?.image === change.image
                ? null
                : {
                      image: change.image,
                      bounds: change.bounds,
                      area: comparison.area,
                  },
        );

    return (
        <aside className="panel editor" aria-label="Volumes">
            <div className="editor-head">
                <h2>Volumes</h2>
                <button
                    className="ghost"
                    onClick={onClose}
                    aria-label="Close volumes"
                >
                    ✕
                </button>
            </div>

            <section>
                <p className="muted hint">
                    Compare surveys of the ground inside an area: terrain from
                    DEMs, or point clouds, from this project's library. Each
                    survey is measured against the one before it, as the volume
                    cut (lowered) and filled (raised), for stockpiles, pits and
                    earthworks. Show a change to see where, red for cut and blue
                    for fill.
                </p>
            </section>

            <section aria-label="Comparisons">
                <h3>Comparisons</h3>
                {comparisons.length === 0 ? (
                    <p className="editor-hint">No comparisons yet.</p>
                ) : (
                    <ul className="view-list">
                        {comparisons.map((c) => (
                            <li
                                key={c.id}
                                className="view-row"
                                aria-label={c.name}
                            >
                                <span className="view-name">{c.name}</span>
                                {c.status === 'measuring' && (
                                    <p className="muted" role="status">
                                        Measuring…
                                    </p>
                                )}
                                {c.status === 'failed' && (
                                    <p className="editor-error" role="alert">
                                        {c.error}
                                    </p>
                                )}
                                {c.status === 'ready' && (
                                    <Changes
                                        comparison={c}
                                        name={name}
                                        shown={overlay?.image ?? null}
                                        onShow={(change) => show(c, change)}
                                    />
                                )}
                                {canEdit && (
                                    <div className="view-actions">
                                        <button
                                            className="ghost"
                                            onClick={() => setEditing(c)}
                                        >
                                            Edit
                                        </button>
                                        <button
                                            className="ghost"
                                            onClick={() => {
                                                if (
                                                    !confirm(
                                                        `Delete ${c.name}? The surveys stay in the library.`,
                                                    )
                                                )
                                                    return;
                                                router.delete(
                                                    TwinSurveyComparisonController.destroy.url(
                                                        [twinId, c.id],
                                                    ),
                                                    RELOAD,
                                                );
                                            }}
                                        >
                                            Delete
                                        </button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {canEdit && editing === null && (
                <section>
                    {surveys.length < 2 ? (
                        <p className="editor-hint">
                            Upload two surveys or more to the project's library
                            to compare them: DEMs, or point clouds with a
                            coordinate system.
                        </p>
                    ) : (
                        <button
                            className="primary"
                            onClick={() => setEditing('new')}
                        >
                            New comparison
                        </button>
                    )}
                </section>
            )}

            {canEdit && editing !== null && (
                <ComparisonForm
                    key={editing === 'new' ? 'new' : editing.id}
                    twinId={twinId}
                    definition={definition}
                    surveys={surveys}
                    comparison={editing === 'new' ? null : editing}
                    onDone={() => setEditing(null)}
                />
            )}
        </aside>
    );
}

function Changes({
    comparison,
    name,
    shown,
    onShow,
}: {
    comparison: SurveyComparisonSummary;
    name: (id: string) => string;
    shown: string | null;
    onShow: (change: SurveyChange) => void;
}) {
    // The running total since the first survey, after each one.
    const totals = comparison.results.map((_, i) =>
        comparison.results.slice(0, i + 1).reduce((sum, c) => sum + c.net, 0),
    );
    return (
        <ol className="volumes" aria-label={`${comparison.name} changes`}>
            <li className="muted">
                First survey: {name(comparison.surveys[0])}
            </li>
            {comparison.results.map((change, i) => {
                const covered = change.coveredM2 / change.areaM2;
                return (
                    <li key={change.after} aria-label={name(change.after)}>
                        <div className="volumes-head">
                            <span className="view-name">
                                {name(change.after)}
                                {covered < 0.95 && (
                                    <span
                                        className="muted"
                                        title="Both surveys cover only part of the area; the rest isn't counted."
                                    >
                                        {' '}
                                        · {Math.round(covered * 100)}% covered
                                    </span>
                                )}
                            </span>
                            <button
                                className={
                                    shown === change.image ? 'primary' : 'ghost'
                                }
                                aria-pressed={shown === change.image}
                                aria-label={`Show the change to ${name(change.after)}`}
                                onClick={() => onShow(change)}
                            >
                                Show
                            </button>
                        </div>
                        <dl className="volumes-figures">
                            <div>
                                <dt>Cut</dt>
                                <dd>{volume(change.cut)}</dd>
                            </div>
                            <div>
                                <dt>Fill</dt>
                                <dd>{volume(change.fill)}</dd>
                            </div>
                            <div>
                                <dt>Net</dt>
                                <dd>{volume(change.net, true)}</dd>
                            </div>
                            <div>
                                <dt>Since first</dt>
                                <dd>{volume(totals[i], true)}</dd>
                            </div>
                        </dl>
                    </li>
                );
            })}
        </ol>
    );
}

function ComparisonForm({
    twinId,
    definition,
    surveys,
    comparison,
    onDone,
}: {
    twinId: string;
    definition: TwinDefinition;
    surveys: TwinAssetSummary[];
    comparison: SurveyComparisonSummary | null;
    onDone: () => void;
}) {
    const form = useForm({
        name: comparison?.name ?? '',
        area: comparison?.area ?? ([] as [number, number][]),
        surveys: comparison?.surveys ?? ([] as string[]),
    });
    const toGround = (area: [number, number][]) =>
        area.map(([lon, lat]) => lonLatToMetres(definition.origin, lon, lat));
    const { sketch, draw, stop } = useSketch((points) =>
        form.setData(
            'area',
            points.map((p) => {
                const { lon, lat } = metresToLonLat(definition.origin, p);
                return [lon, lat] as [number, number];
            }),
        ),
    );
    // The first error for a field or any of its items (area.0.1, surveys.2).
    const error = (field: 'name' | 'area' | 'surveys') =>
        Object.entries(form.errors).find(
            ([key]) => key === field || key.startsWith(`${field}.`),
        )?.[1];
    const toggle = (id: string) =>
        form.setData(
            'surveys',
            form.data.surveys.includes(id)
                ? form.data.surveys.filter((s) => s !== id)
                : [...form.data.surveys, id],
        );

    return (
        <section aria-label={comparison ? 'Edit comparison' : 'New comparison'}>
            <h3>{comparison ? `Edit ${comparison.name}` : 'New comparison'}</h3>
            <form
                className="editor-form"
                onSubmit={(e) => {
                    e.preventDefault();
                    stop();
                    form.submit(
                        comparison
                            ? TwinSurveyComparisonController.update([
                                  twinId,
                                  comparison.id,
                              ])
                            : TwinSurveyComparisonController.store(twinId),
                        { ...RELOAD, onSuccess: onDone },
                    );
                }}
            >
                <label className="editor-field">
                    <span>Name</span>
                    <input
                        value={form.data.name}
                        maxLength={100}
                        placeholder="North stockpile"
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                </label>
                {error('name') && (
                    <p className="editor-error" role="alert">
                        {error('name')}
                    </p>
                )}

                <div className="editor-field">
                    <span>Area</span>
                    <p className="muted">
                        {form.data.area.length === 0
                            ? 'Not drawn yet.'
                            : `${form.data.area.length} points.`}{' '}
                        {sketch
                            ? 'Click the ground to add points; drag one to move it.'
                            : ''}
                    </p>
                    <div className="view-actions">
                        {sketch ? (
                            <>
                                <button type="button" onClick={stop}>
                                    Done drawing
                                </button>
                                <button
                                    type="button"
                                    className="ghost"
                                    disabled={sketch.points.length === 0}
                                    onClick={() =>
                                        draw(sketch.points.slice(0, -1))
                                    }
                                >
                                    Undo point
                                </button>
                            </>
                        ) : (
                            <button
                                type="button"
                                onClick={() => draw(toGround(form.data.area))}
                            >
                                {form.data.area.length
                                    ? 'Redraw in scene'
                                    : 'Draw in scene'}
                            </button>
                        )}
                    </div>
                </div>
                {error('area') && (
                    <p className="editor-error" role="alert">
                        {error('area')}
                    </p>
                )}

                <fieldset className="editor-field volumes-surveys">
                    <legend>Surveys, oldest first</legend>
                    {surveys.map((s) => {
                        const order = form.data.surveys.indexOf(s.id);
                        return (
                            <label key={s.id} className="check">
                                <input
                                    type="checkbox"
                                    checked={order >= 0}
                                    onChange={() => toggle(s.id)}
                                />
                                {order >= 0 ? `${order + 1}. ` : ''}
                                {s.name}
                            </label>
                        );
                    })}
                    <p className="muted">
                        Tick them in the order they were surveyed.
                    </p>
                </fieldset>
                {error('surveys') && (
                    <p className="editor-error" role="alert">
                        {error('surveys')}
                    </p>
                )}

                <div className="view-actions">
                    <button
                        type="submit"
                        className="primary"
                        disabled={form.processing}
                    >
                        {comparison ? 'Save' : 'Compare'}
                    </button>
                    <button
                        type="button"
                        className="ghost"
                        onClick={() => {
                            stop();
                            onDone();
                        }}
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    );
}

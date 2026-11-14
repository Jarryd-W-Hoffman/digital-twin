import { router } from '@inertiajs/react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { store as storeVersion } from '@/routes/twins/versions';
import {
    LISTS,
    choicesFor,
    editNote,
    elementNode,
    fieldsFor,
    fieldsOf,
    formatPoints,
    itemLabel,
    itemsOf,
    newElement,
    newItem,
    parsePoints,
    replaceItem,
    setField,
    type Field,
    type Item,
    type ListName,
} from '@/twin/core/editing';
import type { FeatureRef, Point, TwinDefinition } from '@/twin/core/types';
import { validateDefinition } from '@/twin/core/validate';
import { useTwin } from '@/twin/state/store';

const text = (v: unknown): string =>
    typeof v === 'string' || typeof v === 'number' ? String(v) : '';
const numberOrUndefined = (v: string): number | undefined =>
    v.trim() === '' || !Number.isFinite(Number(v)) ? undefined : Number(v);
const asItem = (v: unknown): Item =>
    typeof v === 'object' && v !== null && !Array.isArray(v) ? (v as Item) : {};

/**
 * A twin's lists (zones, assets, routes, types, rules, scenarios, KPIs and
 * panels) as forms drawn from definition.schema.json. Edits stay a draft,
 * checked as you type by the same validator the server uses, until Save
 * writes them as one new version.
 */
export function DefinitionEditor({
    twinId,
    definition,
    onClose,
}: {
    twinId: string;
    definition: TwinDefinition;
    onClose: () => void;
}) {
    const [draft, setDraft] = useState(definition);
    const [list, setList] = useState<ListName>('zones');
    const [selected, setSelected] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const [serverErrors, setServerErrors] = useState<string[]>([]);
    const errors = useMemo(() => validateDefinition(draft), [draft]);
    const dirty = draft !== definition;
    const items = itemsOf(draft, list);
    const item = selected === null ? undefined : items[selected];
    const noun = LISTS.find((l) => l.list === list)?.noun ?? 'item';

    const close = () => {
        if (!dirty || confirm('Discard your changes to the definition?'))
            onClose();
    };
    const choose = (next: ListName) => {
        setList(next);
        setSelected(null);
    };
    const update = (next: Item | null) => {
        if (selected === null) return;
        setDraft(replaceItem(draft, list, selected, next));
        if (next === null) setSelected(null);
    };
    const add = () => {
        setDraft(replaceItem(draft, list, items.length, newItem(draft, list)));
        setSelected(items.length);
    };
    const save = () => {
        setSaving(true);
        router.post(
            storeVersion.url(twinId),
            {
                definition: draft as unknown as Record<string, never>,
                note: editNote(definition, draft),
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onError: (e) =>
                    setServerErrors(String(e.definition ?? '').split('\n')),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <aside className="panel editor def-editor" aria-label="Definition">
            <div className="editor-head">
                <h2>Definition</h2>
                <button
                    className="ghost"
                    onClick={close}
                    aria-label="Close definition"
                >
                    ✕
                </button>
            </div>
            <div className="def-editor-tabs" role="tablist">
                {LISTS.map((l) => (
                    <button
                        key={l.list}
                        role="tab"
                        aria-selected={l.list === list}
                        className={l.list === list ? 'on' : ''}
                        onClick={() => choose(l.list)}
                    >
                        {l.title}
                    </button>
                ))}
            </div>
            <div className="def-editor-body">
                <nav className="def-editor-lists">
                    <ul aria-label={LISTS.find((l) => l.list === list)?.title}>
                        {items.map((it, index) => (
                            <li key={index}>
                                <button
                                    className={
                                        selected === index ? 'selected' : ''
                                    }
                                    onClick={() => setSelected(index)}
                                >
                                    {itemLabel(it)}
                                    {typeof it.id === 'string' &&
                                        it.id !== itemLabel(it) && (
                                            <span className="muted">
                                                {' '}
                                                {it.id}
                                            </span>
                                        )}
                                </button>
                            </li>
                        ))}
                    </ul>
                    <button className="link" onClick={add}>
                        Add {noun}
                    </button>
                </nav>
                {selected !== null && item ? (
                    <ItemForm
                        key={`${list}-${selected}`}
                        list={list}
                        noun={noun}
                        item={item}
                        draft={draft}
                        onChange={update}
                    />
                ) : (
                    <p className="muted hint def-editor-empty">
                        Choose a {noun} to edit it, or add one. Positions are
                        metres east and north of the twin's origin.
                    </p>
                )}
            </div>
            <div className="def-editor-foot">
                {errors.length > 0 || serverErrors.length > 0 ? (
                    <ul className="editor-error" role="alert">
                        {[...new Set([...errors, ...serverErrors])].map(
                            (error) => (
                                <li key={error}>{error}</li>
                            ),
                        )}
                    </ul>
                ) : (
                    <p className="muted hint">
                        {dirty ? 'No problems.' : 'No changes yet.'}
                    </p>
                )}
                <div className="editor-actions">
                    <button
                        className="primary"
                        onClick={save}
                        disabled={!dirty || errors.length > 0 || saving}
                    >
                        Save
                    </button>
                    <button className="ghost" onClick={close}>
                        {dirty ? 'Discard' : 'Close'}
                    </button>
                </div>
            </div>
        </aside>
    );
}

function ItemForm({
    list,
    noun,
    item,
    draft,
    onChange,
}: {
    list: ListName;
    noun: string;
    item: Item;
    draft: TwinDefinition;
    onChange: (item: Item | null) => void;
}) {
    return (
        <form
            className="def-editor-form"
            aria-label={`Edit ${text(item.id) || noun}`}
            onSubmit={(e) => e.preventDefault()}
        >
            <Fields
                fields={fieldsFor(list, item)}
                item={item}
                owner={item}
                draft={draft}
                onChange={onChange}
            />
            <div className="editor-actions">
                <button
                    type="button"
                    className="danger"
                    onClick={() =>
                        confirm(
                            `Delete ${itemLabel(item)}? Anything that refers to it will be listed as a problem until you change it.`,
                        ) && onChange(null)
                    }
                >
                    Delete {noun}
                </button>
            </div>
        </form>
    );
}

type Context = { owner: Item; draft: TwinDefinition };

/** The fields of one object, each writing back into it. */
function Fields({
    fields,
    item,
    onChange,
    ...context
}: Context & {
    fields: Field[];
    item: Item;
    onChange: (item: Item) => void;
}) {
    return fields.map((field) => (
        <FieldInput
            key={field.key}
            field={field}
            item={item}
            {...context}
            onChange={(value) =>
                onChange(setField(item, field.key, value, field.keepEmpty))
            }
        />
    ));
}

function FieldInput({
    field,
    item,
    owner,
    draft,
    onChange,
}: Context & {
    field: Field;
    item: Item;
    onChange: (value: unknown) => void;
}) {
    const value = item[field.key];
    const context = { owner, draft };
    let input;
    switch (field.widget) {
        case 'id':
        case 'text':
        case 'expr':
        case 'color':
            input = (
                <input
                    type={field.widget === 'color' ? 'color' : 'text'}
                    className={field.widget === 'expr' ? 'mono' : undefined}
                    spellCheck={field.widget === 'expr' ? false : undefined}
                    value={text(value)}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
            break;
        case 'textarea':
            input = (
                <textarea
                    rows={3}
                    value={text(value)}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
            break;
        case 'number':
            input = (
                <input
                    type="number"
                    value={text(value)}
                    onChange={(e) =>
                        onChange(numberOrUndefined(e.target.value))
                    }
                />
            );
            break;
        case 'select':
        case 'zone':
        case 'asset':
        case 'route':
        case 'type':
        case 'metric':
        case 'type-metric':
        case 'state':
        case 'from-state': {
            const choices = choicesFor(field, draft, owner, item);
            if (field.widget === 'metric' && choices.length === 0) {
                input = (
                    <input
                        value={text(value)}
                        onChange={(e) => onChange(e.target.value)}
                    />
                );
                break;
            }
            const current = text(value);
            input = (
                <select
                    value={current}
                    onChange={(e) => onChange(e.target.value)}
                >
                    <option value="">None</option>
                    {current !== '' && !choices.includes(current) && (
                        <option value={current}>{current}</option>
                    )}
                    {choices.map((choice) => (
                        <option key={choice} value={choice}>
                            {choice}
                        </option>
                    ))}
                </select>
            );
            break;
        }
        case 'assets':
            return (
                <AssetsInput
                    field={field}
                    value={value}
                    draft={draft}
                    onChange={onChange}
                />
            );
        case 'point':
            return (
                <PointInput field={field} value={value} onChange={onChange} />
            );
        case 'polygon':
            return (
                <PolygonInput field={field} value={value} onChange={onChange} />
            );
        case 'feature':
            return (
                <FeatureInput
                    field={field}
                    value={value}
                    draft={draft}
                    onChange={onChange}
                />
            );
        case 'params':
            return (
                <ParamsInput field={field} value={value} onChange={onChange} />
            );
        case 'object': {
            const object = asItem(value);
            return (
                <fieldset className="def-editor-field def-editor-group">
                    <legend>{field.title}</legend>
                    <Description field={field} />
                    <Fields
                        fields={fieldsOf(field.node, object)}
                        item={object}
                        {...context}
                        onChange={onChange}
                    />
                </fieldset>
            );
        }
        case 'list':
            return (
                <ListInput
                    field={field}
                    value={value}
                    {...context}
                    onChange={onChange}
                />
            );
    }
    return (
        <div className="def-editor-field">
            <label>
                <span>{field.title}</span>
                {input}
            </label>
            <Description field={field} />
        </div>
    );
}

function Description({ field }: { field: Field }) {
    return field.description ? (
        <small className="muted">{field.description}</small>
    ) : null;
}

/** A nested list of objects: a route's stops, a type's metrics, a panel's figures. */
function ListInput({
    field,
    value,
    onChange,
    ...context
}: Context & {
    field: Field;
    value: unknown;
    onChange: (value: unknown) => void;
}) {
    const elements = Array.isArray(value) ? (value as unknown[]) : [];
    const node = elementNode(field);
    const set = (index: number, next: Item | null) => {
        const copy = [...elements];
        if (next === null) copy.splice(index, 1);
        else copy[index] = next;
        onChange(copy);
    };
    return (
        <fieldset className="def-editor-field">
            <legend>{field.title}</legend>
            <Description field={field} />
            {elements.map((element, index) => {
                const object = asItem(element);
                const name = `${field.title} ${index + 1}`;
                return (
                    <fieldset key={index} className="def-editor-group">
                        <legend>
                            {name}
                            <button
                                type="button"
                                className="link"
                                aria-label={`Remove ${name}`}
                                onClick={() => set(index, null)}
                            >
                                ✕
                            </button>
                        </legend>
                        <Fields
                            fields={fieldsOf(node, object)}
                            item={object}
                            {...context}
                            onChange={(next) => set(index, next)}
                        />
                    </fieldset>
                );
            })}
            <button
                type="button"
                className="link"
                onClick={() => onChange([...elements, newElement(field)])}
            >
                Add to {field.title.toLowerCase()}
            </button>
        </fieldset>
    );
}

function AssetsInput({
    field,
    value,
    draft,
    onChange,
}: {
    field: Field;
    value: unknown;
    draft: TwinDefinition;
    onChange: (value: unknown) => void;
}) {
    const chosen = Array.isArray(value) ? (value as string[]) : [];
    return (
        <fieldset className="def-editor-field">
            <legend>{field.title}</legend>
            {draft.assets
                .filter((a) => !field.ofKind || a.kind === field.ofKind)
                .map((a) => (
                    <label key={a.id} className="editor-check">
                        <input
                            type="checkbox"
                            checked={chosen.includes(a.id)}
                            onChange={(e) =>
                                onChange(
                                    e.target.checked
                                        ? [...chosen, a.id]
                                        : chosen.filter((id) => id !== a.id),
                                )
                            }
                        />
                        {a.name}
                    </label>
                ))}
        </fieldset>
    );
}

function PointInput({
    field,
    value,
    onChange,
}: {
    field: Field;
    value: unknown;
    onChange: (value: unknown) => void;
}) {
    const point = Array.isArray(value) ? (value as unknown[]) : [];
    const { sketch, draw, stop } = useSketch(([first]) => {
        if (first) onChange(first);
    });
    const set = (i: number, v: string) => {
        const next = [point[0], point[1]];
        next[i] = numberOrUndefined(v);
        onChange(next.every((n) => n === undefined) ? undefined : next);
    };
    return (
        <fieldset className="def-editor-field">
            <legend>{field.title}</legend>
            <div className="def-editor-pair">
                {['East (m)', 'North (m)'].map((label, i) => (
                    <label key={label}>
                        <span>{label}</span>
                        <input
                            type="number"
                            value={text(point[i])}
                            onChange={(e) => set(i, e.target.value)}
                        />
                    </label>
                ))}
            </div>
            {sketch ? (
                <small className="muted">
                    Click the ground to move it there, or drag it.
                </small>
            ) : (
                <Description field={field} />
            )}
            <button
                type="button"
                className="link"
                onClick={() =>
                    sketch
                        ? stop()
                        : draw(
                              point.length === 2 &&
                                  point.every((n) => typeof n === 'number')
                                  ? [point as Point]
                                  : [],
                              true,
                          )
                }
            >
                {sketch ? 'Done placing' : 'Place in scene'}
            </button>
        </fieldset>
    );
}

/** An element a converted IFC model lists in its elements.json. */
interface Element {
    id: string;
    name: string;
    ifcClass: string;
}

/**
 * Where a model's element list is: beside a GLB that meridian-ifc wrote,
 * which a library upload serves as `…/files/model.glb`.
 */
const elementsUrl = (url: string | undefined) =>
    url?.endsWith('/files/model.glb')
        ? url.replace(/model\.glb$/, 'elements.json')
        : null;

/**
 * The element of a model or tileset an asset is: a layer, then the feature's
 * id, chosen from a converted IFC model's elements, typed, or picked by
 * clicking it in the scene.
 */
function FeatureInput({
    field,
    value,
    draft,
    onChange,
}: {
    field: Field;
    value: unknown;
    draft: TwinDefinition;
    onChange: (value: unknown) => void;
}) {
    const feature = asItem(value) as Partial<FeatureRef>;
    const layers = [...(draft.tilesets ?? []), ...(draft.models ?? [])];
    const url = elementsUrl(layers.find((l) => l.id === feature.layer)?.url);
    const [loaded, setLoaded] = useState<{
        url: string;
        elements: Element[];
    } | null>(null);
    useEffect(() => {
        if (!url) return;
        let current = true;
        fetch(url)
            .then((res) => (res.ok ? (res.json() as Promise<Element[]>) : null))
            .then((elements) => {
                if (current && Array.isArray(elements))
                    setLoaded({ url, elements });
            })
            .catch(() => undefined);
        return () => {
            current = false;
        };
    }, [url]);
    const elements = loaded && loaded.url === url ? loaded.elements : null;
    const set = (next: Partial<FeatureRef>) => {
        const merged = { ...feature, ...next };
        onChange(
            merged.layer || merged.id
                ? { layer: merged.layer ?? '', id: merged.id ?? '' }
                : undefined,
        );
    };
    const { picking, pick, stop } = useFeaturePick((picked) =>
        onChange(picked),
    );
    const id = feature.id ?? '';
    return (
        <fieldset className="def-editor-field">
            <legend>{field.title}</legend>
            <div className="def-editor-pair">
                <label>
                    <span>Layer</span>
                    <select
                        value={feature.layer ?? ''}
                        onChange={(e) =>
                            set({ layer: e.target.value || undefined })
                        }
                    >
                        <option value="">None</option>
                        {layers.map((l) => (
                            <option key={l.id} value={l.id}>
                                {l.name || l.id}
                            </option>
                        ))}
                    </select>
                </label>
                <label>
                    <span>Feature</span>
                    {elements ? (
                        <select
                            value={id}
                            onChange={(e) =>
                                set({ id: e.target.value || undefined })
                            }
                        >
                            <option value="">None</option>
                            {id !== '' &&
                                !elements.some((e) => e.id === id) && (
                                    <option value={id}>{id}</option>
                                )}
                            {elements.map((e) => (
                                <option key={e.id} value={e.id}>
                                    {e.name || e.id} · {e.ifcClass}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <input
                            value={id}
                            onChange={(e) =>
                                set({ id: e.target.value || undefined })
                            }
                        />
                    )}
                </label>
            </div>
            {picking ? (
                <small className="muted">
                    Click an element of a model or tileset.
                </small>
            ) : (
                <Description field={field} />
            )}
            <button
                type="button"
                className="link"
                onClick={() => (picking ? stop() : pick())}
            >
                {picking ? 'Stop picking' : 'Pick in scene'}
            </button>
        </fieldset>
    );
}

/**
 * Lets one field take the next feature clicked in the scene; leaving the
 * field ends the wait.
 */
function useFeaturePick(onPicked: (feature: FeatureRef) => void) {
    const owner = useId();
    const picking = useTwin(
        (s) => s.featurePick?.owner === owner && !s.featurePick.picked,
    );
    const report = useRef(onPicked);
    useEffect(() => {
        report.current = onPicked;
    });
    useEffect(() => {
        const unsubscribe = useTwin.subscribe((s) => {
            const picked = s.featurePick?.picked;
            if (s.featurePick?.owner !== owner || !picked) return;
            s.setFeaturePick(null);
            report.current(picked);
        });
        return () => {
            unsubscribe();
            if (useTwin.getState().featurePick?.owner === owner)
                useTwin.getState().setFeaturePick(null);
        };
    }, [owner]);
    return {
        picking,
        pick: () => useTwin.getState().setFeaturePick({ owner }),
        stop: () => useTwin.getState().setFeaturePick(null),
    };
}

/**
 * Lets one field draw on the ground: the points drawn in the scene are
 * reported as they change, and leaving the field (another item, or closing
 * the editor) ends its sketch.
 */
export function useSketch(onPoints: (points: Point[]) => void) {
    const owner = useId();
    const sketch = useTwin((s) =>
        s.sketch?.owner === owner ? s.sketch : null,
    );
    const report = useRef(onPoints);
    useEffect(() => {
        report.current = onPoints;
    });
    useEffect(() => {
        const unsubscribe = useTwin.subscribe((s, prev) => {
            if (s.sketch === prev.sketch || s.sketch?.owner !== owner) return;
            report.current(s.sketch.points);
        });
        return () => {
            unsubscribe();
            if (useTwin.getState().sketch?.owner === owner)
                useTwin.getState().setSketch(null);
        };
    }, [owner]);
    return {
        sketch,
        draw: (points: Point[], single = false) =>
            useTwin.getState().setSketch({ owner, points, single }),
        stop: () => useTwin.getState().setSketch(null),
    };
}

function PolygonInput({
    field,
    value,
    onChange,
}: {
    field: Field;
    value: unknown;
    onChange: (value: unknown) => void;
}) {
    const [typed, setTyped] = useState(formatPoints(value));
    const { sketch, draw, stop } = useSketch((points) => {
        setTyped(formatPoints(points));
        if (points.length > 0) onChange(points);
    });
    return (
        <div className="def-editor-field">
            <label>
                <span>{field.title}</span>
                <textarea
                    rows={5}
                    className="mono"
                    value={typed}
                    readOnly={sketch !== null}
                    onChange={(e) => {
                        setTyped(e.target.value);
                        const parsed = parsePoints(e.target.value);
                        if (parsed) onChange(parsed);
                    }}
                />
            </label>
            {sketch ? (
                <>
                    <small className="muted">
                        Click the ground to add a point, or drag a point to move
                        it.
                    </small>
                    <div className="editor-actions">
                        <button
                            type="button"
                            className="link"
                            disabled={sketch.points.length === 0}
                            onClick={() => draw(sketch.points.slice(0, -1))}
                        >
                            Undo point
                        </button>
                        <button
                            type="button"
                            className="link"
                            onClick={() => draw([])}
                        >
                            Start again
                        </button>
                        <button type="button" className="link" onClick={stop}>
                            Done drawing
                        </button>
                    </div>
                </>
            ) : (
                <>
                    {parsePoints(typed) === null ? (
                        <small className="editor-error">
                            Each line needs two numbers: east, north.
                        </small>
                    ) : (
                        <Description field={field} />
                    )}
                    <button
                        type="button"
                        className="link"
                        onClick={() => draw(parsePoints(typed) ?? [])}
                    >
                        Draw in scene
                    </button>
                </>
            )}
        </div>
    );
}

function ParamsInput({
    field,
    value,
    onChange,
}: {
    field: Field;
    value: unknown;
    onChange: (value: unknown) => void;
}) {
    const params = asItem(value) as Record<string, number>;
    const [name, setName] = useState('');
    return (
        <fieldset className="def-editor-field">
            <legend>{field.title}</legend>
            {Object.entries(params).map(([key, n]) => (
                <div key={key} className="def-editor-param">
                    <label>
                        <span>{key}</span>
                        <input
                            type="number"
                            value={text(n)}
                            onChange={(e) => {
                                const v = numberOrUndefined(e.target.value);
                                if (v !== undefined)
                                    onChange({ ...params, [key]: v });
                            }}
                        />
                    </label>
                    <button
                        type="button"
                        className="link"
                        aria-label={`Remove ${key}`}
                        onClick={() =>
                            onChange(
                                Object.fromEntries(
                                    Object.entries(params).filter(
                                        ([k]) => k !== key,
                                    ),
                                ),
                            )
                        }
                    >
                        ✕
                    </button>
                </div>
            ))}
            <div className="def-editor-param">
                <input
                    aria-label="New parameter"
                    placeholder="capacityKw"
                    value={name}
                    onChange={(e) => setName(e.target.value.trim())}
                />
                <button
                    type="button"
                    className="link"
                    disabled={name === '' || name in params}
                    onClick={() => {
                        onChange({ ...params, [name]: 0 });
                        setName('');
                    }}
                >
                    Add
                </button>
            </div>
            <Description field={field} />
        </fieldset>
    );
}

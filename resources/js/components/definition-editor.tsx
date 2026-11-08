import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { store as storeVersion } from '@/routes/twins/versions';
import {
    fieldsFor,
    formatPoints,
    newItem,
    parsePoints,
    replaceItem,
    setField,
    type EditableList,
    type Field,
    type Item,
} from '@/twin/core/editing';
import type { TwinDefinition } from '@/twin/core/types';
import { validateDefinition } from '@/twin/core/validate';

const LISTS: { list: EditableList; title: string; noun: string }[] = [
    { list: 'zones', title: 'Zones', noun: 'zone' },
    { list: 'assets', title: 'Assets', noun: 'asset' },
];

const text = (v: unknown): string =>
    typeof v === 'string' || typeof v === 'number' ? String(v) : '';
const numberOrUndefined = (v: string): number | undefined =>
    v.trim() === '' || !Number.isFinite(Number(v)) ? undefined : Number(v);

/**
 * Zones and assets as forms, drawn from definition.schema.json. Edits stay a
 * draft, checked as you type by the same validator the server uses, until
 * Save writes them as one new version.
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
    const [selected, setSelected] = useState<{
        list: EditableList;
        index: number;
    } | null>(null);
    const [saving, setSaving] = useState(false);
    const [serverErrors, setServerErrors] = useState<string[]>([]);
    const errors = useMemo(() => validateDefinition(draft), [draft]);
    const dirty = draft !== definition;
    const items = (list: EditableList) => draft[list] as unknown as Item[];
    const item = selected ? items(selected.list)[selected.index] : undefined;

    const close = () => {
        if (!dirty || confirm('Discard your changes to zones and assets?'))
            onClose();
    };
    const update = (next: Item | null) => {
        if (!selected) return;
        setDraft(replaceItem(draft, selected.list, selected.index, next));
        if (next === null) setSelected(null);
    };
    const add = (list: EditableList) => {
        const next = {
            ...draft,
            [list]: [...items(list), newItem(draft, list)],
        };
        setDraft(next as TwinDefinition);
        setSelected({ list, index: items(list).length });
    };
    const save = () => {
        setSaving(true);
        router.post(
            storeVersion.url(twinId),
            {
                definition: draft as unknown as Record<string, never>,
                note: 'Edited zones and assets',
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
        <aside
            className="panel editor def-editor"
            aria-label="Zones and assets"
        >
            <div className="editor-head">
                <h2>Zones and assets</h2>
                <button
                    className="ghost"
                    onClick={close}
                    aria-label="Close zones and assets"
                >
                    ✕
                </button>
            </div>
            <div className="def-editor-body">
                <nav className="def-editor-lists">
                    {LISTS.map(({ list, title, noun }) => (
                        <div key={list}>
                            <h3>{title}</h3>
                            <ul aria-label={title}>
                                {items(list).map((it, index) => (
                                    <li key={index}>
                                        <button
                                            className={
                                                selected?.list === list &&
                                                selected.index === index
                                                    ? 'selected'
                                                    : ''
                                            }
                                            onClick={() =>
                                                setSelected({ list, index })
                                            }
                                        >
                                            {text(it.name) ||
                                                text(it.id) ||
                                                '?'}
                                            <span className="muted">
                                                {' '}
                                                {text(it.id)}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                            <button className="link" onClick={() => add(list)}>
                                Add {noun}
                            </button>
                        </div>
                    ))}
                </nav>
                {selected && item ? (
                    <ItemForm
                        key={`${selected.list}-${selected.index}`}
                        list={selected.list}
                        item={item}
                        draft={draft}
                        onChange={update}
                    />
                ) : (
                    <p className="muted hint def-editor-empty">
                        Choose a zone or an asset to edit it, or add one.
                        Positions are metres east and north of the twin's
                        origin.
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
    item,
    draft,
    onChange,
}: {
    list: EditableList;
    item: Item;
    draft: TwinDefinition;
    onChange: (item: Item | null) => void;
}) {
    const noun = list === 'zones' ? 'zone' : 'asset';
    return (
        <form
            className="def-editor-form"
            aria-label={`Edit ${text(item.id) || noun}`}
            onSubmit={(e) => e.preventDefault()}
        >
            {fieldsFor(list, item).map((field) => (
                <FieldInput
                    key={field.key}
                    field={field}
                    item={item}
                    draft={draft}
                    onChange={(value) =>
                        onChange(setField(item, field.key, value))
                    }
                />
            ))}
            <div className="editor-actions">
                <button
                    type="button"
                    className="danger"
                    onClick={() =>
                        confirm(
                            `Delete ${text(item.name) || text(item.id)}? Anything that refers to it will be listed as a problem until you change it.`,
                        ) && onChange(null)
                    }
                >
                    Delete {noun}
                </button>
            </div>
        </form>
    );
}

function FieldInput({
    field,
    item,
    draft,
    onChange,
}: {
    field: Field;
    item: Item;
    draft: TwinDefinition;
    onChange: (value: unknown) => void;
}) {
    const value = item[field.key];
    const ids = (list: { id: string }[] | undefined) =>
        (list ?? []).map((x) => x.id);
    const choices: Record<string, string[]> = {
        select: field.options ?? [],
        zone: ids(draft.zones),
        asset: ids(draft.assets).filter((id) => id !== item.id),
        route: ids(draft.routes),
        type: ids(draft.types),
    };

    let input;
    switch (field.widget) {
        case 'id':
        case 'text':
            input = (
                <input
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
            input = (
                <select
                    value={text(value)}
                    onChange={(e) => onChange(e.target.value)}
                >
                    {field.widget !== 'select' && (
                        <option value="">None</option>
                    )}
                    {field.widget === 'select' && value === undefined && (
                        <option value="">Choose…</option>
                    )}
                    {choices[field.widget].map((id) => (
                        <option key={id} value={id}>
                            {id}
                        </option>
                    ))}
                </select>
            );
            break;
        case 'assets':
            return (
                <fieldset className="def-editor-field">
                    <legend>{field.title}</legend>
                    {draft.assets
                        .filter((a) => !field.ofKind || a.kind === field.ofKind)
                        .map((a) => {
                            const chosen = Array.isArray(value)
                                ? (value as string[])
                                : [];
                            return (
                                <label key={a.id} className="editor-check">
                                    <input
                                        type="checkbox"
                                        checked={chosen.includes(a.id)}
                                        onChange={(e) =>
                                            onChange(
                                                e.target.checked
                                                    ? [...chosen, a.id]
                                                    : chosen.filter(
                                                          (id) => id !== a.id,
                                                      ),
                                            )
                                        }
                                    />
                                    {a.name}
                                </label>
                            );
                        })}
                </fieldset>
            );
        case 'point':
            return (
                <PointInput field={field} value={value} onChange={onChange} />
            );
        case 'polygon':
            return (
                <PolygonInput field={field} value={value} onChange={onChange} />
            );
        case 'params':
            return (
                <ParamsInput field={field} value={value} onChange={onChange} />
            );
    }
    return (
        <label className="def-editor-field">
            <span>{field.title}</span>
            {input}
            {field.description && (
                <small className="muted">{field.description}</small>
            )}
        </label>
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
            {field.description && (
                <small className="muted">{field.description}</small>
            )}
        </fieldset>
    );
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
    const points = parsePoints(typed);
    return (
        <label className="def-editor-field">
            <span>{field.title}</span>
            <textarea
                rows={5}
                value={typed}
                onChange={(e) => {
                    setTyped(e.target.value);
                    const parsed = parsePoints(e.target.value);
                    if (parsed) onChange(parsed);
                }}
            />
            {points === null ? (
                <small className="editor-error">
                    Each line needs two numbers: east, north.
                </small>
            ) : (
                field.description && (
                    <small className="muted">{field.description}</small>
                )
            )}
        </label>
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
    const params =
        typeof value === 'object' && value !== null && !Array.isArray(value)
            ? (value as Record<string, number>)
            : {};
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
                        onClick={() => {
                            const { [key]: _removed, ...rest } = params;
                            onChange(rest);
                        }}
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
            {field.description && (
                <small className="muted">{field.description}</small>
            )}
        </fieldset>
    );
}

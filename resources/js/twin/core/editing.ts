import { metricsFor } from './metrics.ts';
import { definitionSchema } from './schema.ts';
import type { Point, TwinDefinition } from './types.ts';

/**
 * What the definition editor needs from the schema: which fields an item
 * has, how to show each, and small edits that keep a definition's JSON tidy
 * (an emptied field is removed rather than left blank).
 */
export type ListName =
    | 'zones'
    | 'assets'
    | 'routes'
    | 'types'
    | 'rules'
    | 'scenarios'
    | 'kpis'
    | 'panels'
    | 'shapes';
export type Item = Record<string, unknown>;
export type Node = Record<string, unknown>;

export const LISTS: { list: ListName; title: string; noun: string }[] = [
    { list: 'zones', title: 'Zones', noun: 'zone' },
    { list: 'assets', title: 'Assets', noun: 'asset' },
    { list: 'routes', title: 'Routes', noun: 'route' },
    { list: 'types', title: 'Types', noun: 'type' },
    { list: 'rules', title: 'Rules', noun: 'rule' },
    { list: 'scenarios', title: 'Scenarios', noun: 'scenario' },
    { list: 'kpis', title: 'KPIs', noun: 'KPI' },
    { list: 'panels', title: 'Panels', noun: 'panel' },
    { list: 'shapes', title: 'Shapes', noun: 'shape' },
];

export type Widget =
    | 'id'
    | 'text'
    | 'textarea'
    | 'expr'
    | 'number'
    | 'color'
    | 'select'
    | 'zone'
    | 'asset'
    | 'assets'
    | 'route'
    | 'type'
    | 'metric'
    | 'type-metric'
    | 'state'
    | 'from-state'
    | 'point'
    | 'polygon'
    | 'feature'
    | 'params'
    | 'list'
    | 'object';

export interface Field {
    key: string;
    title: string;
    description?: string;
    widget: Widget;
    /** Choices for a `select`. */
    options?: string[];
    /** For an `assets` or `asset` picker: the kind of asset it lists. */
    ofKind?: string;
    /** Kept when emptied, because the engine reads it (a route's stops). */
    keepEmpty: boolean;
    /** The field's own schema, for the fields of a nested object or list element. */
    node: Node;
}

const node = (v: unknown): Node =>
    typeof v === 'object' && v !== null && !Array.isArray(v) ? (v as Node) : {};
const text = (v: unknown): string | undefined =>
    typeof v === 'string' ? v : undefined;

/** The schema for one item of a top-level list. */
export const listItemNode = (list: ListName): Node =>
    node(node(node(definitionSchema.properties)[list]).items);

/** The schema for one element of a nested list field. */
export const elementNode = (field: Field): Node => node(field.node.items);

/** Every property a node describes, wherever its conditions put it. */
function properties(at: Node, into: Map<string, Node>): Map<string, Node> {
    for (const [key, value] of Object.entries(node(at.properties)))
        if (!into.has(key)) into.set(key, node(value));
    const parts = [
        at.then,
        at.else,
        ...(Array.isArray(at.allOf) ? at.allOf : []),
    ];
    for (const part of parts)
        if (part !== undefined) properties(node(part), into);
    return into;
}

/**
 * The fields to show for an object, in the schema's `x-order` (then the
 * order they're declared), skipping those whose `x-when` doesn't match.
 */
export function fieldsOf(at: Node, item: Item): Field[] {
    const all = properties(at, new Map());
    const order = Array.isArray(at['x-order'])
        ? (at['x-order'] as string[])
        : [];
    const keys = [
        ...order.filter((k) => all.has(k)),
        ...[...all.keys()].filter((k) => !order.includes(k)),
    ];
    return keys.flatMap((key) => {
        const field = all.get(key) as Node;
        const when = node(field['x-when']);
        if (
            Object.entries(when).some(
                ([other, values]) =>
                    !(values as unknown[]).includes(item[other]),
            )
        )
            return [];
        const widget = (key === 'id' ? 'id' : field['x-form']) as
            | Widget
            | undefined;
        if (!widget) return [];
        const options = field['x-options'] ?? field.enum;
        return [
            {
                key,
                title: text(field.title) ?? key,
                description: text(field.description),
                widget,
                options: Array.isArray(options)
                    ? (options as string[])
                    : undefined,
                ofKind: text(field['x-of-kind']),
                keepEmpty: field['x-keep-empty'] === true,
                node: field,
            },
        ];
    });
}

/** The fields for an item of a top-level list. */
export const fieldsFor = (list: ListName, item: Item): Field[] =>
    fieldsOf(listItemNode(list), item);

const ids = (items: unknown, key = 'id'): string[] =>
    (Array.isArray(items) ? items : []).flatMap((x) => {
        const id = node(x)[key];
        return typeof id === 'string' ? [id] : [];
    });

/**
 * What a picker offers. `owner` is the top-level item being edited (a type,
 * for its states and metrics); `item` is the object the field belongs to.
 */
export function choicesFor(
    field: Field,
    def: TwinDefinition,
    owner: Item,
    item: Item,
): string[] {
    switch (field.widget) {
        case 'zone':
            return ids(def.zones);
        case 'route':
            return ids(def.routes);
        case 'type':
            return ids(def.types);
        case 'asset':
            return def.assets
                .filter(
                    (a) =>
                        a.id !== owner.id &&
                        (!field.ofKind || a.kind === field.ofKind),
                )
                .map((a) => a.id);
        case 'metric': {
            const asset =
                def.assets.find((a) => a.id === item.asset) ??
                def.assets.find((a) => a.kind === item.kind);
            return asset
                ? metricsFor(def, asset)
                      .filter((m) => !m.hidden)
                      .map((m) => m.key)
                : [];
        }
        case 'type-metric':
            return ids(owner.metrics, 'key');
        case 'state':
            return ids(owner.states);
        case 'from-state':
            return ['*', ...ids(owner.states)];
        default:
            return field.options ?? [];
    }
}

const isEmpty = (v: unknown, keepEmpty: boolean): boolean =>
    v === undefined ||
    (!keepEmpty &&
        (v === '' ||
            (Array.isArray(v) && v.length === 0) ||
            (typeof v === 'object' &&
                v !== null &&
                !Array.isArray(v) &&
                Object.keys(v).length === 0)));

/** The item with one field changed; an empty value removes the field, unless it's kept. */
export function setField(
    item: Item,
    key: string,
    value: unknown,
    keepEmpty = false,
): Item {
    const next = { ...item };
    if (isEmpty(value, keepEmpty)) delete next[key];
    else next[key] = value;
    return next;
}

/** The items of a list, or none if the definition has none. */
export const itemsOf = (def: TwinDefinition, list: ListName): Item[] =>
    Array.isArray(def[list]) ? (def[list] as unknown as Item[]) : [];

/** The definition with one item of a list replaced (or removed, given null). */
export function replaceItem(
    def: TwinDefinition,
    list: ListName,
    index: number,
    item: Item | null,
): TwinDefinition {
    const items = [...itemsOf(def, list)];
    if (item === null) items.splice(index, 1);
    else items[index] = item;
    return { ...def, [list]: items } as TwinDefinition;
}

/** How an item is listed: its name, title or label, else its id. */
export function itemLabel(item: Item): string {
    for (const key of ['name', 'title', 'label', 'id']) {
        const value = item[key];
        if (typeof value === 'string' && value !== '') return value;
    }
    return '?';
}

/** Which lists differ, as a version note: "Edited zones and rules". */
export function editNote(
    before: TwinDefinition,
    after: TwinDefinition,
): string {
    const changed = LISTS.filter(
        ({ list }) =>
            JSON.stringify(before[list]) !== JSON.stringify(after[list]),
    ).map(({ title, list }) => (list === 'kpis' ? title : title.toLowerCase()));
    const last = changed.pop();
    if (last === undefined) return 'Edited the definition';
    return `Edited ${changed.length > 0 ? `${changed.join(', ')} and ${last}` : last}`;
}

/** "east, north" per line, as typed into a footprint box. */
export function formatPoints(points: unknown): string {
    return Array.isArray(points)
        ? points
              .map((p) => (Array.isArray(p) ? p.join(', ') : String(p)))
              .join('\n')
        : '';
}

/** The points typed, or null while any line isn't two numbers. */
export function parsePoints(text: string): Point[] | null {
    const lines = text
        .split('\n')
        .map((l) => l.trim())
        .filter((l) => l !== '');
    const points = lines.map((line) =>
        line
            .split(/[\s,]+/)
            .filter((n) => n !== '')
            .map(Number),
    );
    return points.every(
        (p) => p.length === 2 && p.every((n) => Number.isFinite(n)),
    )
        ? (points as Point[])
        : null;
}

/** `base`, or `base-2`, `base-3`… whichever isn't taken. */
export function uniqueId(base: string, taken: Iterable<unknown>): string {
    const used = new Set(taken);
    if (!used.has(base)) return base;
    let n = 2;
    while (used.has(`${base}-${n}`)) n++;
    return `${base}-${n}`;
}

/** A new element for a nested list, from the schema's `x-new`. */
export const newElement = (field: Field): Item => ({
    ...node(field.node['x-new']),
});

const square = (half: number): Point[] => [
    [-half, -half],
    [half, -half],
    [half, half],
    [-half, half],
];

/** A new item for the end of a list, sound enough to run as it is. */
export function newItem(def: TwinDefinition, list: ListName): Item {
    const id = (base: string) => uniqueId(base, ids(def[list]));
    switch (list) {
        case 'zones':
            return { id: id('zone'), name: 'New zone', footprint: square(10) };
        case 'routes':
            return {
                id: id('route'),
                name: 'New route',
                path: [
                    [0, 0],
                    [20, 0],
                ],
                stops: [],
            };
        case 'types':
            return {
                id: id('equipment'),
                name: 'New type',
                primary: 'value',
                metrics: [{ key: 'value', unit: '', default: 0 }],
                visual: { color: '#7f8c8d', shape: 'box' },
            };
        case 'rules': {
            const first = def.assets.at(0);
            const metric = first
                ? metricsFor(def, first).find((m) => !m.hidden)?.key
                : undefined;
            return {
                id: id('rule'),
                name: 'New rule',
                severity: 'warning',
                ...(first ? { asset: first.id } : { kind: 'building' }),
                ...(metric ? { metric } : {}),
                op: 'gt',
                threshold: 0,
                message: '{asset} needs a look',
            };
        }
        case 'scenarios':
            return {
                id: id('scenario'),
                name: 'New scenario',
                description: '',
                faults: [],
            };
        case 'kpis':
            return { label: 'New figure', value: '0' };
        case 'panels':
            return { title: 'New panel', items: [] };
        case 'shapes':
            return {
                id: id('arrow'),
                name: 'New arrow',
                kind: 'vector',
                position: [0, 0],
                height: 5,
                heading: '0',
                length: '20',
            };
        case 'assets': {
            const main = def.assets.find((a) => a.kind === 'bus' && !a.fedBy);
            return {
                id: id('building'),
                name: 'New building',
                kind: 'building',
                ...(def.zones[0] ? { zone: def.zones[0].id } : {}),
                footprint: square(5),
                height: 4,
                ...(main ? { fedBy: main.id } : {}),
            };
        }
    }
}

import { definitionSchema } from './schema.ts';
import type { Point, TwinDefinition } from './types.ts';

/**
 * What the definition editor needs from the schema: which fields a zone or
 * an asset has, how to show each, and small edits that keep a definition's
 * JSON tidy (an emptied field is removed rather than left blank).
 */
export type EditableList = 'assets' | 'zones';
export type Item = Record<string, unknown>;

export type Widget =
    | 'id'
    | 'text'
    | 'number'
    | 'select'
    | 'zone'
    | 'asset'
    | 'assets'
    | 'route'
    | 'type'
    | 'point'
    | 'polygon'
    | 'params';

export interface Field {
    key: string;
    title: string;
    description?: string;
    widget: Widget;
    options?: string[];
    /** For an `assets` widget: the kind of asset it lists. */
    ofKind?: string;
}

type Node = Record<string, unknown>;

const node = (v: unknown): Node =>
    typeof v === 'object' && v !== null && !Array.isArray(v) ? (v as Node) : {};

/** The schema for one item of a list: its id, then the fields checked once the id is sound. */
function itemSchema(list: EditableList): Node {
    const items = node(node(node(definitionSchema.properties)[list]).items);
    return {
        ...node(items.properties),
        ...node(node(items.then).properties),
    };
}

/** The fields to show for an item, in the schema's order, skipping those for other kinds. */
export function fieldsFor(list: EditableList, item: Item): Field[] {
    return Object.entries(itemSchema(list)).flatMap(([key, raw]) => {
        const field = node(raw);
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
        return [
            {
                key,
                title: typeof field.title === 'string' ? field.title : key,
                description:
                    typeof field.description === 'string'
                        ? field.description
                        : undefined,
                widget,
                options: Array.isArray(field.enum)
                    ? (field.enum as string[])
                    : undefined,
                ofKind:
                    typeof field['x-of-kind'] === 'string'
                        ? field['x-of-kind']
                        : undefined,
            },
        ];
    });
}

const isEmpty = (v: unknown): boolean =>
    v === undefined ||
    v === '' ||
    (Array.isArray(v) && v.length === 0) ||
    (typeof v === 'object' &&
        v !== null &&
        !Array.isArray(v) &&
        Object.keys(v).length === 0);

/** The item with one field changed; an empty value removes the field. */
export function setField(item: Item, key: string, value: unknown): Item {
    const next = { ...item };
    if (isEmpty(value)) delete next[key];
    else next[key] = value;
    return next;
}

/** The definition with one item of a list replaced (or removed, given null). */
export function replaceItem(
    def: TwinDefinition,
    list: EditableList,
    index: number,
    item: Item | null,
): TwinDefinition {
    const items = [...(def[list] as unknown as Item[])];
    if (item === null) items.splice(index, 1);
    else items[index] = item;
    return { ...def, [list]: items } as TwinDefinition;
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

/** A new item for the end of a list: a 20 m square zone, or an asset at the origin. */
export function newItem(def: TwinDefinition, list: EditableList): Item {
    if (list === 'zones') {
        return {
            id: uniqueId(
                'zone',
                def.zones.map((z) => z.id),
            ),
            name: 'New zone',
            footprint: [
                [-10, -10],
                [10, -10],
                [10, 10],
                [-10, 10],
            ],
        };
    }
    const main = def.assets.find((a) => a.kind === 'bus' && !a.fedBy);
    return {
        id: uniqueId(
            'building',
            def.assets.map((a) => a.id),
        ),
        name: 'New building',
        kind: 'building',
        ...(def.zones[0] ? { zone: def.zones[0].id } : {}),
        footprint: [
            [-5, -5],
            [5, -5],
            [5, 5],
            [-5, 5],
        ],
        height: 4,
        ...(main ? { fedBy: main.id } : {}),
    };
}

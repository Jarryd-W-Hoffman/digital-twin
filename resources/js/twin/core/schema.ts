import Ajv2020, { type ErrorObject } from 'ajv/dist/2020.js';
import schema from './definition.schema.json' with { type: 'json' };

/**
 * The structural half of a definition's checks, from definition.schema.json.
 * app/Support/DefinitionSchema.php reads the same file with opis and turns its
 * errors into the same messages in the same order.
 */
type Node = Record<string, unknown>;
type Segment = string | number;

/** Keywords that fail on their own; the rest only collect their children's failures. */
const ASSERTIONS = new Set([
    'type',
    'required',
    'enum',
    'const',
    'minimum',
    'maximum',
    'exclusiveMinimum',
    'exclusiveMaximum',
    'minLength',
    'pattern',
    'minItems',
    'maxItems',
    'anyOf',
    'not',
]);

const ajv = new Ajv2020({ allErrors: true, strict: false });
const check = ajv.compile(schema);

export const definitionSchema = schema as Node;

/** Prints a value the way String() would, so the PHP mirror can match it. */
export const show = (v: unknown): string =>
    Array.isArray(v)
        ? v.map(show).join(',')
        : typeof v === 'object' && v !== null
          ? '[object Object]'
          : String(v as string | number | boolean | null | undefined);

const isObj = (v: unknown): v is Node =>
    typeof v === 'object' && v !== null && !Array.isArray(v);

/** The schema node at a path of keys from the root. */
function nodeAt(path: string[]): Node | undefined {
    let node: unknown = schema;
    for (const key of path)
        node =
            isObj(node) || Array.isArray(node)
                ? (node as Node)[key]
                : undefined;
    return isObj(node) ? node : undefined;
}

/** A failing check's message: its own, or the nearest one above it. */
function messageFor(path: string[], keyword: string): string {
    const own = nodeAt(path)?.['x-messages'];
    if (isObj(own) && typeof own[keyword] === 'string') return own[keyword];
    // The root has a message, so the walk always ends with one.
    for (let i = path.length; ; i--) {
        const message = nodeAt(path.slice(0, i))?.['x-message'];
        if (typeof message === 'string') return message;
    }
}

/** Walks the instance, turning array indexes into numbers. */
function segments(input: unknown, pointer: string): Segment[] {
    const out: Segment[] = [];
    let value = input;
    for (const raw of pointer.split('/').slice(1)) {
        const key = raw.replaceAll('~1', '/').replaceAll('~0', '~');
        const segment = Array.isArray(value) ? Number(key) : key;
        out.push(segment);
        value =
            isObj(value) || Array.isArray(value)
                ? (value as Record<Segment, unknown>)[segment]
                : undefined;
    }
    return out;
}

/** Fills {N:field} with a field of the Nth list item on the path, and {value} with what's there. */
function format(message: string, input: unknown, path: Segment[]): string {
    const items: unknown[] = [];
    let value = input;
    for (const segment of path) {
        const parent = value;
        value =
            isObj(parent) || Array.isArray(parent)
                ? (parent as Record<Segment, unknown>)[segment]
                : undefined;
        if (Array.isArray(parent)) items.push(value);
    }
    return message
        .replace(/\{(\d+):(\w+)\}/g, (_, n: string, field: string) => {
            const item = items[Number(n)];
            const text = isObj(item) ? item[field] : undefined;
            if (typeof text === 'number' && Number.isFinite(text))
                return String(text);
            return typeof text === 'string' && text !== '' ? text : '?';
        })
        .replace('{value}', show(value));
}

export function comparePaths(a: Segment[], b: Segment[]): number {
    for (let i = 0; i < Math.min(a.length, b.length); i++) {
        const x = a[i];
        const y = b[i];
        if (x === y) continue;
        if (typeof x === 'number' && typeof y === 'number') return x - y;
        if (typeof x === 'number') return -1;
        if (typeof y === 'number') return 1;
        return x < y ? -1 : 1;
    }
    return a.length - b.length;
}

function located(input: unknown, e: ErrorObject): [Segment[], string] {
    const schemaPath = e.schemaPath.split('/').slice(1, -1);
    const path = segments(input, e.instancePath);
    if (e.keyword === 'required') {
        const missing = (e.params as { missingProperty: string })
            .missingProperty;
        const child = nodeAt([...schemaPath, 'properties', missing])?.[
            'x-message'
        ];
        const at = [...path, missing];
        return [
            at,
            format(
                typeof child === 'string'
                    ? child
                    : messageFor(schemaPath, e.keyword),
                input,
                at,
            ),
        ];
    }
    return [path, format(messageFor(schemaPath, e.keyword), input, path)];
}

/**
 * Every structural problem, ordered by where it is in the definition (list
 * items by index, fields by name), each reported once.
 */
export function schemaErrors(input: unknown): string[] {
    if (check(input)) return [];
    const found = (check.errors ?? [])
        .filter((e) => ASSERTIONS.has(e.keyword))
        .map((e) => located(input, e))
        .sort(
            ([a, x], [b, y]) =>
                comparePaths(a, b) || (x < y ? -1 : x > y ? 1 : 0),
        );
    return [...new Set(found.map(([, message]) => message))];
}

/**
 * Twin definitions say which format they're in (`format`, 1 when absent), so
 * one saved by an older Meridian can be brought up to date rather than break.
 * app/Support/DefinitionFormat.php does the same, and
 * tests/fixtures/definition-migrations.json checks both.
 */

/** The format this Meridian writes, and the newest it reads. */
export const CURRENT_FORMAT = 2;

type Obj = Record<string, unknown>;

/**
 * The definition in the current format. Anything that isn't an object, or
 * says it's in a format this Meridian doesn't know, is left as it is for the
 * validator to refuse.
 */
export function migrateDefinition(input: unknown): unknown {
    if (typeof input !== 'object' || input === null || Array.isArray(input))
        return input;
    const format = (input as Obj).format ?? 1;
    if (
        !Number.isInteger(format) ||
        (format as number) < 1 ||
        (format as number) > CURRENT_FORMAT
    )
        return input;

    let def = { ...(input as Obj) };
    if ((format as number) < 2) def = terrainsAsAList(def);

    const { format: _old, ...rest } = def;
    return { format: CURRENT_FORMAT, ...rest };
}

/**
 * 1 to 2: a twin can stand on several terrains, one at a time, so its
 * `terrain` becomes the first of its `terrains`.
 */
function terrainsAsAList(def: Obj): Obj {
    if (!('terrain' in def)) return def;
    const { terrain, ...rest } = def;
    return { ...rest, terrains: [terrain] };
}

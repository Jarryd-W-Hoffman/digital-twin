import { evaluate, parseExpression } from './expr.ts';
import { centroid } from './geo.ts';
import type { Vec3 } from './measure.ts';
import { typeOf } from './metrics.ts';
import { lookDirection } from './sight.ts';
import type { ShapeDef, TwinDefinition, Values } from './types.ts';

export const SHAPE_COLOURS = { vector: '#7fd1ff', fan: '#ffd166' } as const;

/** A shape where it stands, and which way it points for any frame's values. */
export interface CompiledShape {
    def: ShapeDef;
    /** Metres east, north and up of the site's origin, up from the ground. */
    origin: Vec3;
    colour: string;
    /** Heading, tilt and (for a vector) length now, or null if a value is missing. */
    pose: (values: Values) => Pose | null;
}

export interface Pose {
    headingDeg: number;
    tiltDeg: number;
    length: number;
}

/** Where a shape stands: its point or its asset's top, raised by its own height. */
export function shapeOrigin(def: TwinDefinition, shape: ShapeDef): Vec3 | null {
    const raise = shape.height ?? 0;
    if (shape.position) return [shape.position[0], shape.position[1], raise];
    const asset = def.assets.find((a) => a.id === shape.asset);
    if (!asset) return null;
    const at = asset.footprint ? centroid(asset.footprint) : asset.position;
    if (!at) return null;
    const top = asset.height ?? typeOf(def, asset)?.visual.height ?? 4;
    return [at[0], at[1], top + raise];
}

/** An expression read against a frame's values: `{asset.metric}` only. */
function reader(
    src: string | undefined,
    fallback: number,
): (values: Values) => number | undefined {
    if (src === undefined) return () => fallback;
    const node = parseExpression(src);
    return (values) => {
        const v = evaluate(node, {
            name: () => undefined,
            ref: (asset, metric) => values[`${asset}.${metric}`],
        });
        return Number.isFinite(v) ? v : undefined;
    };
}

export function compileShape(
    def: TwinDefinition,
    shape: ShapeDef,
): CompiledShape | null {
    const origin = shapeOrigin(def, shape);
    if (!origin) return null;
    const heading = reader(shape.heading, 0);
    const tilt = reader(shape.tilt, 0);
    const length = reader(shape.length, shape.radius ?? 0);
    return {
        def: shape,
        origin,
        colour: shape.color ?? SHAPE_COLOURS[shape.kind],
        pose: (values) => {
            const h = heading(values);
            const t = tilt(values);
            const l = length(values);
            if (h === undefined || t === undefined || l === undefined)
                return null;
            return {
                headingDeg: ((h % 360) + 360) % 360,
                tiltDeg: Math.max(-90, Math.min(90, t)),
                length: Math.max(0, l),
            };
        },
    };
}

/** A vector's tip, from its origin. */
export function vectorTip(origin: Vec3, pose: Pose): Vec3 {
    const d = lookDirection(pose.headingDeg, pose.tiltDeg);
    return [
        origin[0] + d[0] * pose.length,
        origin[1] + d[1] * pose.length,
        origin[2] + d[2] * pose.length,
    ];
}

/**
 * A fan's surface as triangles, in its own frame: metres east, north and up
 * of where it stands, pointing north and level. It's turned to its heading
 * and tilt when drawn, so it's only rebuilt when its size changes. A flat
 * fan is a slice of a disc; one with height is a slice of a shell, closed
 * by its top, bottom and sides. `edges` outlines it.
 */
export function fanMesh(
    radius: number,
    acrossDeg: number,
    upDeg: number,
    stepDeg = 5,
): { positions: number[]; indices: number[]; edges: number[] } {
    const positions: number[] = [];
    const indices: number[] = [];
    const edges: number[] = [];
    const add = (p: Vec3) => positions.push(...p) / 3 - 1;
    const at = (az: number, el: number): Vec3 => {
        const d = lookDirection(az, el);
        return [d[0] * radius, d[1] * radius, d[2] * radius];
    };
    const steps = (span: number) => Math.max(1, Math.ceil(span / stepDeg));
    const across = Math.min(acrossDeg, 360);
    const azimuths = Array.from(
        { length: steps(across) + 1 },
        (_, i) => -across / 2 + (i * across) / steps(across),
    );
    const full = across >= 360;
    const centre = add([0, 0, 0]);
    /** A row of points at one elevation, joined to the centre: a flat slice. */
    const slice = (el: number) => {
        const row = azimuths.map((az) => add(at(az, el)));
        for (let i = 0; i + 1 < row.length; i++)
            indices.push(centre, row[i]!, row[i + 1]!);
        for (let i = 0; i + 1 < row.length; i++)
            edges.push(row[i]!, row[i + 1]!);
        return row;
    };
    if (upDeg <= 0) {
        const row = slice(0);
        if (!full) edges.push(centre, row[0]!, centre, row.at(-1)!);
        return { positions, indices, edges };
    }
    const up = Math.min(upDeg, 180);
    const elevations = Array.from(
        { length: steps(up) + 1 },
        (_, j) => -up / 2 + (j * up) / steps(up),
    );
    const grid = elevations.map((el) => azimuths.map((az) => add(at(az, el))));
    // The shell, out at the radius.
    for (let j = 0; j + 1 < grid.length; j++) {
        for (let i = 0; i + 1 < azimuths.length; i++) {
            const [a, b, c, d] = [
                grid[j]![i]!,
                grid[j]![i + 1]!,
                grid[j + 1]![i + 1]!,
                grid[j + 1]![i]!,
            ];
            indices.push(a, b, c, a, c, d);
        }
    }
    // Its bottom and top, each a cone back to the centre.
    for (const row of [grid[0]!, grid.at(-1)!]) {
        for (let i = 0; i + 1 < row.length; i++)
            indices.push(centre, row[i]!, row[i + 1]!);
        for (let i = 0; i + 1 < row.length; i++)
            edges.push(row[i]!, row[i + 1]!);
    }
    // Its sides, unless it goes all the way round.
    if (!full) {
        for (const i of [0, azimuths.length - 1]) {
            const column = grid.map((row) => row[i]!);
            for (let j = 0; j + 1 < column.length; j++)
                indices.push(centre, column[j]!, column[j + 1]!);
            for (let j = 0; j + 1 < column.length; j++)
                edges.push(column[j]!, column[j + 1]!);
            edges.push(centre, column[0]!, centre, column.at(-1)!);
        }
    }
    return { positions, indices, edges };
}

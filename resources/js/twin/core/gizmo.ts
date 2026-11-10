import { closestAlongLine, type Vec3 } from './measure.ts';
import type { Placement } from './types.ts';

/**
 * Moving a placed model or tileset by dragging handles in the scene. All of
 * it is in the site's frame: metres east, north and up from its origin, the
 * same as a placement.
 */
export type Handle = 'east' | 'north' | 'up' | 'slide' | 'turn';

export const AXES: Record<'east' | 'north' | 'up', Vec3> = {
    east: [1, 0, 0],
    north: [0, 1, 0],
    up: [0, 0, 1],
};

/** A ray from the camera, in the site's frame. */
export interface Ray {
    origin: Vec3;
    direction: Vec3;
}

/** Where a handle's grip is along the pointer's ray: a distance, a point or an angle. */
export type Grip = number | [number, number];

export const centreOf = (p: Placement): Vec3 => [p.east, p.north, p.up];

/** Where a ray meets the level at `height`, east and north; null when it never does. */
export function onLevel(height: number, ray: Ray): [number, number] | null {
    const dz = ray.direction[2];
    if (Math.abs(dz) < 1e-9) return null;
    const t = (height - ray.origin[2]) / dz;
    if (t <= 0) return null;
    return [
        ray.origin[0] + t * ray.direction[0],
        ray.origin[1] + t * ray.direction[1],
    ];
}

/** Degrees clockwise from north of `point` seen from `centre`. */
export function bearing(
    centre: [number, number],
    point: [number, number],
): number {
    const deg =
        (Math.atan2(point[0] - centre[0], point[1] - centre[1]) * 180) /
        Math.PI;
    return (deg + 360) % 360;
}

/** How far along its handle, or where on the level, the pointer's ray holds a handle. */
export function grip(
    handle: Handle,
    placement: Placement,
    ray: Ray,
): Grip | null {
    const centre = centreOf(placement);
    if (handle === 'slide') return onLevel(placement.up, ray);
    if (handle === 'turn') {
        const at = onLevel(placement.up, ray);
        return at && bearing([centre[0], centre[1]], at);
    }
    return closestAlongLine(centre, AXES[handle], ray.origin, ray.direction);
}

const tenth = (v: number) => Math.round(v * 10) / 10;

/**
 * The placement once a handle held at `from` is dragged to `to`. Positions
 * are kept to 10 cm and headings to whole degrees, as the editor's fields are.
 */
export function dragged(
    start: Placement,
    handle: Handle,
    from: Grip,
    to: Grip,
): Placement {
    if (handle === 'slide') {
        const [fe, fn] = from as [number, number];
        const [te, tn] = to as [number, number];
        return {
            ...start,
            east: tenth(start.east + te - fe),
            north: tenth(start.north + tn - fn),
        };
    }
    if (handle === 'turn') {
        const turned = start.headingDeg + (to as number) - (from as number);
        return {
            ...start,
            headingDeg: ((Math.round(turned) % 360) + 360) % 360,
        };
    }
    const by = (to as number) - (from as number);
    return { ...start, [handle]: tenth(start[handle] + by) };
}

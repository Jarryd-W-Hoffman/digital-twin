import type { Vec3 } from './measure.ts';
import type { Point } from './types.ts';

/**
 * What can be seen from a point: a sensor's shape, where it stands and which
 * way it looks. Positions are metres east and north of the site's origin;
 * heights are above the ground.
 */
export type SensorShape = 'cone' | 'rectangle' | 'dome';

export interface Sensor {
    shape: SensorShape;
    at: Point;
    /** Height of the sensor above the ground (or the surface it was placed on). */
    height: number;
    /** Degrees clockwise from north. */
    headingDeg: number;
    /** Degrees above the level; negative looks down. */
    tiltDeg: number;
    /** How far it sees, in metres. */
    range: number;
    /** A cone's full angle, in degrees. */
    coneDeg: number;
    /** A rectangle's full angles across and up, in degrees. */
    widthDeg: number;
    heightDeg: number;
}

export const SENSOR_DEFAULTS: Omit<Sensor, 'at' | 'height'> = {
    shape: 'cone',
    headingDeg: 0,
    tiltDeg: -15,
    range: 150,
    coneDeg: 60,
    widthDeg: 90,
    heightDeg: 50,
};

/** The shapes' limits, as the panel's sliders offer them. */
export const LIMITS = {
    range: [10, 1000],
    coneDeg: [5, 150],
    widthDeg: [5, 150],
    heightDeg: [5, 150],
    tiltDeg: [-90, 90],
} as const;

const rad = (deg: number) => (deg * Math.PI) / 180;
const deg = (r: number) => (r * 180) / Math.PI;

/** The way a sensor looks, as a unit vector east, north and up. */
export function lookDirection(headingDeg: number, tiltDeg: number): Vec3 {
    const h = rad(headingDeg);
    const t = rad(tiltDeg);
    return [Math.cos(t) * Math.sin(h), Math.cos(t) * Math.cos(h), Math.sin(t)];
}

/** Heading and tilt that look from one point to another, both east-north-up. */
export function aimAt(
    from: Vec3,
    to: Vec3,
): { headingDeg: number; tiltDeg: number } | null {
    const [e, n, u] = [to[0] - from[0], to[1] - from[1], to[2] - from[2]];
    const level = Math.hypot(e, n);
    if (level < 1e-6 && Math.abs(u) < 1e-6) return null;
    const headingDeg = Math.round(((deg(Math.atan2(e, n)) % 360) + 360) % 360);
    const tiltDeg = Math.round(deg(Math.atan2(u, level)));
    return { headingDeg, tiltDeg };
}

/**
 * The perspective frustum that holds a cone or rectangle: Cesium's `fov`
 * spans the wider side, and the aspect ratio is width over height. A cone
 * is cut to its circle afterwards; the frustum is the square around it.
 */
export function frustumOf(sensor: Sensor): {
    fov: number;
    aspectRatio: number;
} {
    if (sensor.shape === 'rectangle') {
        // Angles combine through their tangents, not linearly.
        const across = Math.tan(rad(sensor.widthDeg) / 2);
        const up = Math.tan(rad(sensor.heightDeg) / 2);
        return {
            fov: rad(Math.max(sensor.widthDeg, sensor.heightDeg)),
            aspectRatio: across / up,
        };
    }
    return { fov: rad(sensor.coneDeg), aspectRatio: 1 };
}

/** Whether a direction from the sensor falls inside its shape, ignoring range. */
export function withinShape(sensor: Sensor, direction: Vec3): boolean {
    if (sensor.shape === 'dome') return true;
    const look = lookDirection(sensor.headingDeg, sensor.tiltDeg);
    const length = Math.hypot(...direction);
    if (length === 0) return false;
    const d: Vec3 = [
        direction[0] / length,
        direction[1] / length,
        direction[2] / length,
    ];
    const along = d[0] * look[0] + d[1] * look[1] + d[2] * look[2];
    if (along <= 0) return false;
    if (sensor.shape === 'cone')
        return Math.acos(Math.min(1, along)) <= rad(sensor.coneDeg) / 2 + 1e-9;
    // Rectangle: the sensor's right and up axes, then each side's angle.
    const right = lookDirection(sensor.headingDeg + 90, 0);
    const up: Vec3 = [
        right[1] * look[2] - right[2] * look[1],
        right[2] * look[0] - right[0] * look[2],
        right[0] * look[1] - right[1] * look[0],
    ];
    const x = d[0] * right[0] + d[1] * right[1] + d[2] * right[2];
    const y = d[0] * up[0] + d[1] * up[1] + d[2] * up[2];
    return (
        Math.abs(x / along) <= Math.tan(rad(sensor.widthDeg) / 2) + 1e-9 &&
        Math.abs(y / along) <= Math.tan(rad(sensor.heightDeg) / 2) + 1e-9
    );
}

/** A line of sight: clear to the target, or blocked so far along it. */
export type Sight =
    | { clear: true; distance: number }
    | { clear: false; distance: number; blockedAt: number };

/**
 * Judges a line of sight from what a ray toward the target hit first. A hit
 * within `tolerance` of the target is the target itself, not something in
 * the way.
 */
export function judgeSight(
    distance: number,
    firstHit: number | null,
    tolerance = 0.5,
): Sight {
    if (firstHit === null || firstHit >= distance - tolerance)
        return { clear: true, distance };
    return { clear: false, distance, blockedAt: firstHit };
}

const COMPASS = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
/** A heading as a compass point and degrees: "NE · 45°". */
export function formatHeading(headingDeg: number): string {
    return `${COMPASS[Math.round(headingDeg / 45) % 8]} · ${Math.round(headingDeg)}°`;
}

import type { Point } from './types.ts';

const EARTH_RADIUS_M = 6378137;

/** Equirectangular local projection; accurate to centimetres at campus scale. */
export function metresToLonLat(
    origin: { lon: number; lat: number },
    [x, y]: Point,
): { lon: number; lat: number } {
    const lat = origin.lat + (y / EARTH_RADIUS_M) * (180 / Math.PI);
    const lon =
        origin.lon +
        (x / (EARTH_RADIUS_M * Math.cos((origin.lat * Math.PI) / 180))) *
            (180 / Math.PI);
    return { lon, lat };
}

/** The inverse of metresToLonLat. */
export function lonLatToMetres(
    origin: { lon: number; lat: number },
    lon: number,
    lat: number,
): Point {
    const y = ((lat - origin.lat) * Math.PI * EARTH_RADIUS_M) / 180;
    const x =
        ((lon - origin.lon) *
            Math.PI *
            EARTH_RADIUS_M *
            Math.cos((origin.lat * Math.PI) / 180)) /
        180;
    return [x, y];
}

export function centroid(points: Point[]): Point {
    let x = 0;
    let y = 0;
    for (const [px, py] of points) {
        x += px;
        y += py;
    }
    const n = points.length || 1;
    return [x / n, y / n];
}

/** Walks a closed path; returns position and heading at distance `d`. */
export function pointAlong(
    path: Point[],
    d: number,
): { x: number; y: number; heading: number; length: number } {
    const lengths = pathSegments(path);
    const length = lengths.reduce((a, b) => a + b, 0);
    let rest = ((d % length) + length) % length;
    for (let i = 0; i < path.length; i++) {
        const seg = lengths[i]!;
        if (rest <= seg || i === path.length - 1) {
            const [ax, ay] = path[i]!;
            const [bx, by] = path[(i + 1) % path.length]!;
            const f = seg === 0 ? 0 : rest / seg;
            return {
                x: ax + (bx - ax) * f,
                y: ay + (by - ay) * f,
                heading: Math.atan2(bx - ax, by - ay),
                length,
            };
        }
        rest -= seg;
    }
    /* c8 ignore next */
    return { x: path[0]![0], y: path[0]![1], heading: 0, length };
}

export function pathSegments(path: Point[]): number[] {
    return path.map(([ax, ay], i) => {
        const [bx, by] = path[(i + 1) % path.length]!;
        return Math.hypot(bx - ax, by - ay);
    });
}

/** Distance along a closed path to the start of vertex `index`. */
export function distanceToVertex(path: Point[], index: number): number {
    return pathSegments(path)
        .slice(0, index)
        .reduce((a, b) => a + b, 0);
}

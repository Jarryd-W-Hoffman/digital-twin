/**
 * Measuring in the scene: the arithmetic, kept apart from Cesium so it can
 * be tested. Points are Earth-centred (ECEF) metres with their height above
 * the ellipsoid, which is exact at any distance; areas are taken in a local
 * east-north plane, which is exact enough at a site's scale.
 */

export type Vec3 = [number, number, number];

export type MeasureTool = 'distance' | 'height' | 'area' | 'point';
/** Holds a distance's second point level with the first, or straight above or below it. */
export type MeasureLock = 'none' | 'horizontal' | 'vertical';

/** A picked point: where it is, and its height above the ellipsoid. */
export interface MeasuredPoint {
    position: Vec3;
    lon: number;
    lat: number;
    height: number;
    /** The ground's height under it, when known. */
    ground?: number;
}

export type Measurement =
    | {
          tool: 'distance';
          points: MeasuredPoint[];
          slant: number;
          horizontal: number;
          vertical: number;
      }
    | { tool: 'height'; points: MeasuredPoint[]; aboveGround: number }
    | {
          tool: 'area';
          points: MeasuredPoint[];
          area: number;
          perimeter: number;
      }
    | {
          tool: 'point';
          points: MeasuredPoint[];
          aboveGround: number | null;
          /** Degrees from level, and the downhill direction clockwise from north. */
          slope: number | null;
          aspect: number | null;
      };

const sub = (a: Vec3, b: Vec3): Vec3 => [a[0] - b[0], a[1] - b[1], a[2] - b[2]];
const dot = (a: Vec3, b: Vec3) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
const length = (a: Vec3) => Math.sqrt(dot(a, a));

/**
 * A distance in three parts: straight through the air, along the level,
 * and up or down (positive when the second point is higher).
 */
export function distanceParts(
    a: MeasuredPoint,
    b: MeasuredPoint,
): { slant: number; horizontal: number; vertical: number } {
    const slant = length(sub(b.position, a.position));
    const vertical = b.height - a.height;
    return {
        slant,
        horizontal: Math.sqrt(Math.max(0, slant * slant - vertical * vertical)),
        vertical,
    };
}

/**
 * How far along a line (from `origin`, in direction `direction`) the point
 * nearest a ray is: where a vertical-locked point goes as the pointer moves.
 * Null when the ray runs parallel to the line.
 */
export function closestAlongLine(
    origin: Vec3,
    direction: Vec3,
    rayOrigin: Vec3,
    rayDirection: Vec3,
): number | null {
    const w = sub(origin, rayOrigin);
    const a = dot(direction, direction);
    const b = dot(direction, rayDirection);
    const c = dot(rayDirection, rayDirection);
    const d = dot(direction, w);
    const e = dot(rayDirection, w);
    const denominator = a * c - b * b;
    if (Math.abs(denominator) < 1e-12 * a * c) return null;
    return (b * e - c * d) / denominator;
}

/** The area inside a ring of east-north points, however it winds. */
export function planArea(points: [number, number][]): number {
    let twice = 0;
    for (let i = 0; i < points.length; i++) {
        const [x1, y1] = points[i]!;
        const [x2, y2] = points[(i + 1) % points.length]!;
        twice += x1 * y2 - x2 * y1;
    }
    return Math.abs(twice) / 2;
}

/** The length around a closed ring of points. */
export function perimeter(points: Vec3[]): number {
    if (points.length < 2) return 0;
    let total = 0;
    for (let i = 0; i < points.length; i++)
        total += length(sub(points[(i + 1) % points.length]!, points[i]!));
    return points.length === 2 ? total / 2 : total;
}

/**
 * The slope of the ground at a point, from its height a little east, west,
 * north and south of it, `spacing` metres away: degrees from level, and the
 * direction it falls towards, clockwise from north.
 */
export function slopeOf(
    heights: { east: number; west: number; north: number; south: number },
    spacing: number,
): { slope: number; aspect: number | null } {
    const dzdx = (heights.east - heights.west) / (2 * spacing);
    const dzdy = (heights.north - heights.south) / (2 * spacing);
    const rise = Math.hypot(dzdx, dzdy);
    const slope = (Math.atan(rise) * 180) / Math.PI;
    if (rise < 1e-9) return { slope: 0, aspect: null };
    // Downhill is against the gradient.
    const aspect = ((Math.atan2(-dzdx, -dzdy) * 180) / Math.PI + 360) % 360;
    return { slope, aspect };
}

const grouped = (n: number, digits: number) =>
    n.toLocaleString('en', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });

/** 0.42 m, 12.3 m, 1.25 km: a length as someone on site would say it. */
export function formatLength(metres: number): string {
    const sign = metres < 0 ? '−' : '';
    const m = Math.abs(metres);
    if (m >= 1000) return `${sign}${grouped(m / 1000, 2)} km`;
    return `${sign}${grouped(m, m < 10 ? 2 : 1)} m`;
}

/** 85.0 m², 1,234 m², 2.35 ha, 1.20 km². */
export function formatArea(squareMetres: number): string {
    if (squareMetres >= 1_000_000)
        return `${grouped(squareMetres / 1_000_000, 2)} km²`;
    if (squareMetres >= 10_000)
        return `${grouped(squareMetres / 10_000, 2)} ha`;
    return `${grouped(squareMetres, squareMetres < 100 ? 1 : 0)} m²`;
}

/** −36.848460°, as precise as a centimetre or so. */
export const formatDegrees = (degrees: number): string =>
    `${degrees < 0 ? '−' : ''}${Math.abs(degrees).toFixed(6)}°`;

const COMPASS = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'] as const;

/** 12.4° facing NE. */
export function formatSlope(slope: number, aspect: number | null): string {
    if (aspect === null || slope < 0.05) return 'level';
    return `${slope.toFixed(1)}° facing ${COMPASS[Math.round(aspect / 45) % 8]}`;
}

import type { Point } from './types.ts';

/**
 * Cutting the site open to see inside: everything above a height, and
 * everything on one side of a vertical cut, is taken away. Heights are in
 * metres above the site's ground; positions in metres east and north of
 * its origin.
 */
export interface Section {
    /** Cut away everything higher than this above the ground, when set. */
    above: number | null;
    /** A vertical cut: the line `offset` metres from the origin, facing `heading`. */
    across: { offset: number; heading: number } | null;
}

/** A plane in the site's east-north-up frame: kept where `normal·p + distance ≥ 0`. */
export interface SitePlane {
    normal: [number, number, number];
    distance: number;
}

/** The way a vertical cut faces, as a unit vector east and north: the side taken away. */
export function facing(heading: number): Point {
    const r = (heading * Math.PI) / 180;
    // Rounded so that due north is exactly [0, 1], not 6e-17 east of it.
    const clean = (v: number) => Math.round(v * 1e12) / 1e12 || 0;
    return [clean(Math.sin(r)), clean(Math.cos(r))];
}

/** The planes that keep what a section keeps, as Cesium clipping planes are given. */
export function sectionPlanes(section: Section): SitePlane[] {
    const planes: SitePlane[] = [];
    if (section.above !== null)
        planes.push({ normal: [0, 0, -1], distance: section.above });
    if (section.across) {
        const [e, n] = facing(section.across.heading);
        // Kept where (p - c)·f ≤ 0, c being `offset` along f from the origin.
        planes.push({ normal: [-e, -n, 0], distance: section.across.offset });
    }
    return planes;
}

/**
 * The part of a footprint on the kept side of a vertical cut, by clipping
 * the polygon against the half-plane (Sutherland–Hodgman). Empty when the
 * cut takes it all.
 */
export function clipFootprint(
    footprint: Point[],
    across: Section['across'],
): Point[] {
    if (!across) return footprint;
    const [fe, fn] = facing(across.heading);
    const side = ([e, n]: Point) => e * fe + n * fn - across.offset;
    const kept: Point[] = [];
    footprint.forEach((p, i) => {
        const q = footprint[(i + 1) % footprint.length]!;
        const sp = side(p);
        const sq = side(q);
        if (sp <= 0) kept.push(p);
        if (sp < 0 !== sq < 0 && sp !== 0 && sq !== 0) {
            const t = sp / (sp - sq);
            kept.push([p[0] + t * (q[0] - p[0]), p[1] + t * (q[1] - p[1])]);
        }
    });
    return kept.length >= 3 ? kept : [];
}

export interface Body {
    footprint: Point[];
    base: number;
    top: number;
}

/** What's left of a block once cut, or null when nothing is. */
export function cutBody(body: Body, section: Section | null): Body | null {
    if (!section) return body;
    const top =
        section.above === null ? body.top : Math.min(body.top, section.above);
    if (top <= body.base) return null;
    const footprint = clipFootprint(body.footprint, section.across);
    return footprint.length ? { footprint, base: body.base, top } : null;
}

/** How far the site reaches from its origin, rounded up to 10 m: the cut's range. */
export function reach(points: Point[]): number {
    const far = Math.max(0, ...points.map(([e, n]) => Math.hypot(e, n)));
    return Math.max(10, Math.ceil(far / 10) * 10);
}

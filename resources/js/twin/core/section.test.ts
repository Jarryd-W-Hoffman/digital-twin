import { describe, expect, it } from 'vitest';
import {
    clipFootprint,
    cutBody,
    facing,
    reach,
    sectionPlanes,
} from './section.ts';
import type { Point } from './types.ts';

const square: Point[] = [
    [-10, -10],
    [10, -10],
    [10, 10],
    [-10, 10],
];
const area = (ring: Point[]) =>
    Math.abs(
        ring.reduce((sum, [x, y], i) => {
            const [x2, y2] = ring[(i + 1) % ring.length]!;
            return sum + x * y2 - x2 * y;
        }, 0) / 2,
    );

describe('facing', () => {
    it('turns clockwise from north', () => {
        expect(facing(0)).toEqual([0, 1]);
        expect(facing(90)).toEqual([1, 0]);
        expect(facing(180)).toEqual([0, -1]);
        expect(facing(270)).toEqual([-1, 0]);
    });
});

describe('sectionPlanes', () => {
    it('is empty for a section that cuts nothing', () => {
        expect(sectionPlanes({ above: null, across: null })).toEqual([]);
    });

    it('keeps what is below the height', () => {
        const [plane] = sectionPlanes({ above: 6, across: null });
        // Kept where -z + 6 >= 0.
        expect(plane).toEqual({ normal: [0, 0, -1], distance: 6 });
    });

    it('keeps what is behind the cut, the side it does not face', () => {
        const [plane] = sectionPlanes({
            above: null,
            across: { offset: 20, heading: 90 },
        });
        const kept = (e: number) => plane!.normal[0] * e + plane!.distance >= 0;
        expect(kept(19)).toBe(true);
        expect(kept(21)).toBe(false);
    });

    it('gives both planes when both cuts are on', () => {
        expect(
            sectionPlanes({ above: 3, across: { offset: 0, heading: 0 } }),
        ).toHaveLength(2);
    });
});

describe('clipFootprint', () => {
    it('leaves a footprint alone without a vertical cut', () => {
        expect(clipFootprint(square, null)).toBe(square);
    });

    it('keeps the half behind a cut through the middle', () => {
        const half = clipFootprint(square, { offset: 0, heading: 0 });
        expect(area(half)).toBeCloseTo(200);
        expect(Math.max(...half.map(([, n]) => n))).toBeCloseTo(0);
    });

    it('keeps all of a footprint behind the cut, and none in front of it', () => {
        expect(area(clipFootprint(square, { offset: 50, heading: 90 }))).toBe(
            400,
        );
        expect(clipFootprint(square, { offset: -50, heading: 90 })).toEqual([]);
    });

    it('cuts on a slant', () => {
        // The line e + n = 0 through the corners: half the square either side.
        const half = clipFootprint(square, { offset: 0, heading: 45 });
        expect(area(half)).toBeCloseTo(200);
    });

    it('does not double a corner the cut passes through', () => {
        const half = clipFootprint(square, { offset: 0, heading: 45 });
        expect(half).toHaveLength(3);
    });
});

describe('cutBody', () => {
    const body = { footprint: square, base: 0.5, top: 18 };

    it('leaves a body whole without a section', () => {
        expect(cutBody(body, null)).toBe(body);
    });

    it('lowers the top to the cut', () => {
        expect(cutBody(body, { above: 6, across: null })).toEqual({
            ...body,
            top: 6,
        });
        expect(cutBody(body, { above: 30, across: null })?.top).toBe(18);
    });

    it('takes away a body wholly above the cut or in front of it', () => {
        expect(cutBody(body, { above: 0.5, across: null })).toBeNull();
        expect(
            cutBody(body, { above: null, across: { offset: -20, heading: 0 } }),
        ).toBeNull();
    });

    it('cuts both ways at once', () => {
        const cut = cutBody(body, {
            above: 10,
            across: { offset: 0, heading: 0 },
        })!;
        expect(cut.top).toBe(10);
        expect(area(cut.footprint)).toBeCloseTo(200);
    });
});

describe('reach', () => {
    it('is the farthest point from the origin, rounded up to 10 m', () => {
        expect(
            reach([
                [30, 40],
                [-10, 0],
            ]),
        ).toBe(50);
        expect(reach([[51, 0]])).toBe(60);
    });

    it('is at least 10 m', () => {
        expect(reach([])).toBe(10);
    });
});

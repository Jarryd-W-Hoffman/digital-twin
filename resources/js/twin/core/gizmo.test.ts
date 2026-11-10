import { describe, expect, it } from 'vitest';
import { bearing, dragged, grip, onLevel, type Ray } from './gizmo.ts';
import type { Placement } from './types.ts';

const at: Placement = { east: 10, north: 20, up: 0, headingDeg: 30, scale: 1 };
/** Looking straight down from 100 m above a point. */
const down = (e: number, n: number): Ray => ({
    origin: [e, n, 100],
    direction: [0, 0, -1],
});

describe('onLevel', () => {
    it('meets the level below', () => {
        expect(onLevel(0, down(3, 4))).toEqual([3, 4]);
        expect(
            onLevel(5, { origin: [0, 0, 10], direction: [1, 0, -1] }),
        ).toEqual([5, 0]);
    });

    it('never meets a level it runs alongside, or one behind it', () => {
        expect(
            onLevel(0, { origin: [0, 0, 10], direction: [1, 0, 0] }),
        ).toBeNull();
        expect(
            onLevel(0, { origin: [0, 0, 10], direction: [0, 0, 1] }),
        ).toBeNull();
    });
});

describe('bearing', () => {
    it('is clockwise from north', () => {
        expect(bearing([0, 0], [0, 5])).toBe(0);
        expect(bearing([0, 0], [5, 0])).toBe(90);
        expect(bearing([0, 0], [0, -5])).toBe(180);
        expect(bearing([0, 0], [-5, 0])).toBe(270);
    });
});

describe('grip and dragged', () => {
    it('moves along an axis by as far as the pointer moved along it', () => {
        const from = grip('east', at, down(12, 20))!;
        const to = grip('east', at, down(17.04, 26))!;
        expect(dragged(at, 'east', from, to)).toEqual({ ...at, east: 15 });
    });

    it('moves up along the vertical, from a ray seen from the side', () => {
        const side = (z: number): Ray => ({
            origin: [10, -80, z],
            direction: [0, 1, 0],
        });
        const from = grip('up', at, side(2))!;
        const to = grip('up', at, side(5.5))!;
        expect(dragged(at, 'up', from, to)).toEqual({ ...at, up: 3.5 });
    });

    it('slides across the level', () => {
        const from = grip('slide', at, down(10, 20))!;
        const to = grip('slide', at, down(4.02, 31))!;
        expect(dragged(at, 'slide', from, to)).toEqual({
            ...at,
            east: 4,
            north: 31,
        });
    });

    it('turns by the angle swept around the centre, to whole degrees', () => {
        const from = grip('turn', at, down(10, 30))!; // due north of the centre
        const to = grip('turn', at, down(20, 20))!; // due east
        expect(dragged(at, 'turn', from, to)).toEqual({
            ...at,
            headingDeg: 120,
        });
        // Back past north, it wraps.
        const back = grip('turn', at, down(0, 20))!; // due west
        expect(dragged(at, 'turn', from, back).headingDeg).toBe(300);
    });

    it('has no grip when the ray misses', () => {
        const flat: Ray = { origin: [0, 0, 10], direction: [1, 0, 0] };
        expect(grip('slide', at, flat)).toBeNull();
        expect(grip('turn', at, flat)).toBeNull();
        expect(
            grip('east', at, { origin: [0, 0, 10], direction: [1, 0, 0] }),
        ).toBeNull();
    });
});

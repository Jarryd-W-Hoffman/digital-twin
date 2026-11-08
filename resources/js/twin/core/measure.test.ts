import { describe, expect, it } from 'vitest';
import {
    closestAlongLine,
    distanceParts,
    formatArea,
    formatDegrees,
    formatLength,
    formatSlope,
    type MeasuredPoint,
    perimeter,
    planArea,
    slopeOf,
    type Vec3,
} from './measure.ts';

const at = (position: Vec3, height: number): MeasuredPoint => ({
    position,
    lon: 0,
    lat: 0,
    height,
});

describe('distances', () => {
    it('splits a distance into along the level and up or down', () => {
        // Locally flat: up is z.
        expect(distanceParts(at([0, 0, 10], 10), at([30, 40, 10], 10))).toEqual(
            { slant: 50, horizontal: 50, vertical: 0 },
        );
        const steep = distanceParts(at([0, 0, 0], 0), at([0, 5, 12], 12));
        expect(steep).toEqual({ slant: 13, horizontal: 5, vertical: 12 });
        expect(
            distanceParts(at([0, 5, 12], 12), at([0, 0, 0], 0)).vertical,
        ).toBe(-12);
    });

    it('never makes the level part imaginary when heights and positions disagree slightly', () => {
        expect(
            distanceParts(at([0, 0, 0], 0), at([0, 0, 3], 3.0000001))
                .horizontal,
        ).toBe(0);
    });

    it('finds where along a vertical line the pointer’s ray passes nearest', () => {
        // A line straight up from the origin; a ray across at height 7.
        expect(
            closestAlongLine([0, 0, 0], [0, 0, 1], [-10, 2, 7], [1, 0, 0]),
        ).toBeCloseTo(7);
        // A ray looking down at it.
        expect(
            closestAlongLine([0, 0, 0], [0, 0, 2], [10, 0, 20], [-1, 0, -1]),
        ).toBeCloseTo(5);
        expect(
            closestAlongLine([0, 0, 0], [0, 0, 1], [5, 0, 0], [0, 0, 1]),
        ).toBeNull();
    });
});

describe('areas', () => {
    it('measures a ring of points either way round', () => {
        const square: [number, number][] = [
            [0, 0],
            [20, 0],
            [20, 10],
            [0, 10],
        ];
        expect(planArea(square)).toBe(200);
        expect(planArea([...square].reverse())).toBe(200);
        expect(
            planArea([
                [0, 0],
                [10, 0],
                [0, 10],
            ]),
        ).toBe(50);
        expect(
            planArea([
                [0, 0],
                [10, 0],
            ]),
        ).toBe(0);
    });

    it('measures the way round, and a line there and back', () => {
        expect(
            perimeter([
                [0, 0, 0],
                [20, 0, 0],
                [20, 10, 0],
                [0, 10, 0],
            ]),
        ).toBe(60);
        expect(
            perimeter([
                [0, 0, 0],
                [3, 4, 0],
            ]),
        ).toBe(5);
        expect(perimeter([[0, 0, 0]])).toBe(0);
    });
});

describe('slopes', () => {
    it('gives the slope and the way it falls', () => {
        // Rising 1 m in 1 m to the east: 45°, falling to the west.
        expect(slopeOf({ east: 1, west: -1, north: 0, south: 0 }, 1)).toEqual({
            slope: 45,
            aspect: 270,
        });
        const north = slopeOf({ east: 0, west: 0, north: -0.1, south: 0.1 }, 1);
        expect(north.slope).toBeCloseTo(5.71, 2);
        expect(north.aspect).toBeCloseTo(0);
        expect(slopeOf({ east: 2, west: 2, north: 2, south: 2 }, 1)).toEqual({
            slope: 0,
            aspect: null,
        });
    });
});

describe('formatting', () => {
    it.each([
        [0.423, '0.42 m'],
        [12.34, '12.3 m'],
        [999.94, '999.9 m'],
        [1250, '1.25 km'],
        [-3.5, '−3.50 m'],
        [12_345_678, '12,345.68 km'],
    ])('%s m is %s', (metres, text) => {
        expect(formatLength(metres)).toBe(text);
    });

    it.each([
        [85, '85.0 m²'],
        [1234.4, '1,234 m²'],
        [23_500, '2.35 ha'],
        [1_200_000, '1.20 km²'],
    ])('%s m² is %s', (area, text) => {
        expect(formatArea(area)).toBe(text);
    });

    it('writes degrees and slopes', () => {
        expect(formatDegrees(-36.8484597)).toBe('−36.848460°');
        expect(formatDegrees(174.76)).toBe('174.760000°');
        expect(formatSlope(12.44, 44)).toBe('12.4° facing NE');
        expect(formatSlope(3, 350)).toBe('3.0° facing N');
        expect(formatSlope(0.01, 90)).toBe('level');
        expect(formatSlope(0, null)).toBe('level');
    });
});

import { describe, expect, it } from 'vitest';
import {
    aimAt,
    formatHeading,
    frustumOf,
    judgeSight,
    lookDirection,
    SENSOR_DEFAULTS,
    type Sensor,
    withinShape,
} from './sight.ts';

const sensor = (over: Partial<Sensor> = {}): Sensor => ({
    ...SENSOR_DEFAULTS,
    at: [0, 0],
    height: 5,
    tiltDeg: 0,
    ...over,
});
const close = (v: number[], w: number[]) =>
    v.forEach((x, i) => expect(x).toBeCloseTo(w[i]!));

describe('lookDirection', () => {
    it('looks clockwise from north, and up by the tilt', () => {
        close(lookDirection(0, 0), [0, 1, 0]);
        close(lookDirection(90, 0), [1, 0, 0]);
        close(lookDirection(0, 90), [0, 0, 1]);
        close(lookDirection(180, -45), [0, -Math.SQRT1_2, -Math.SQRT1_2]);
    });
});

describe('aimAt', () => {
    it('gives the heading and tilt from one point to another', () => {
        expect(aimAt([0, 0, 10], [100, 0, 10])).toEqual({
            headingDeg: 90,
            tiltDeg: 0,
        });
        expect(aimAt([0, 0, 10], [0, -10, 0])).toEqual({
            headingDeg: 180,
            tiltDeg: -45,
        });
        expect(aimAt([0, 0, 0], [-5, 5, 0])).toEqual({
            headingDeg: 315,
            tiltDeg: 0,
        });
    });

    it('has nowhere to aim at itself', () => {
        expect(aimAt([1, 2, 3], [1, 2, 3])).toBeNull();
    });
});

describe('frustumOf', () => {
    it('holds a cone in a square frustum of its angle', () => {
        const f = frustumOf(sensor({ coneDeg: 60 }));
        expect(f.fov).toBeCloseTo(Math.PI / 3);
        expect(f.aspectRatio).toBe(1);
    });

    it('holds a rectangle with its wider angle and the ratio of its tangents', () => {
        const f = frustumOf(
            sensor({ shape: 'rectangle', widthDeg: 90, heightDeg: 53.13 }),
        );
        expect(f.fov).toBeCloseTo(Math.PI / 2);
        expect(f.aspectRatio).toBeCloseTo(2, 2); // tan 45° / tan 26.565°
    });
});

describe('withinShape', () => {
    it('takes a cone as a circle around where it looks', () => {
        const cone = sensor({ coneDeg: 60, headingDeg: 0 });
        expect(withinShape(cone, [0, 10, 0])).toBe(true);
        expect(
            withinShape(cone, [Math.tan(Math.PI / 6) * 10 * 0.99, 10, 0]),
        ).toBe(true);
        expect(
            withinShape(cone, [Math.tan(Math.PI / 6) * 10 * 1.01, 10, 0]),
        ).toBe(false);
        // The square's corner is outside the circle.
        const t = Math.tan(Math.PI / 6) * 10 * 0.9;
        expect(withinShape(cone, [t, 10, t])).toBe(false);
        expect(withinShape(cone, [0, -10, 0])).toBe(false);
    });

    it('takes a rectangle by its two angles', () => {
        const rect = sensor({
            shape: 'rectangle',
            headingDeg: 90,
            widthDeg: 90,
            heightDeg: 30,
        });
        expect(withinShape(rect, [10, 9.9, 0])).toBe(true); // 44.7° across
        expect(withinShape(rect, [10, 10.2, 0])).toBe(false);
        expect(withinShape(rect, [10, 0, 2.6])).toBe(true); // 14.6° up
        expect(withinShape(rect, [10, 0, 2.8])).toBe(false);
        expect(withinShape(rect, [10, 9.9, 2.6])).toBe(true); // both corners at once
    });

    it('takes everything around a dome', () => {
        expect(withinShape(sensor({ shape: 'dome' }), [0, -10, -3])).toBe(true);
    });

    it('takes nothing for no direction at all', () => {
        expect(withinShape(sensor(), [0, 0, 0])).toBe(false);
    });
});

describe('judgeSight', () => {
    it('is clear when nothing is hit, or only the target', () => {
        expect(judgeSight(120, null)).toEqual({ clear: true, distance: 120 });
        expect(judgeSight(120, 119.7)).toEqual({ clear: true, distance: 120 });
    });

    it('is blocked by anything nearer', () => {
        expect(judgeSight(120, 41.2)).toEqual({
            clear: false,
            distance: 120,
            blockedAt: 41.2,
        });
    });
});

describe('formatHeading', () => {
    it('names the compass point', () => {
        expect(formatHeading(0)).toBe('N · 0°');
        expect(formatHeading(44)).toBe('NE · 44°');
        expect(formatHeading(359)).toBe('N · 359°');
    });
});

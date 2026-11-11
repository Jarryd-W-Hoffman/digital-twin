import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { compileShape, fanMesh, shapeOrigin, vectorTip } from './shapes.ts';
import type { ShapeDef, TwinDefinition } from './types.ts';

const def = JSON.parse(
    readFileSync(
        new URL(
            '../../../../public/samples/harbour-depot.json',
            import.meta.url,
        ),
        'utf8',
    ),
) as TwinDefinition;
const wx = def.assets.find((a) => a.id === 'wx')!;
const close = (v: number[], w: number[]) =>
    v.forEach((x, i) => expect(x).toBeCloseTo(w[i]!));

describe('shapeOrigin', () => {
    it('stands on a point, raised by its height', () => {
        expect(
            shapeOrigin(def, {
                id: 's',
                kind: 'fan',
                position: [3, 4],
                height: 6,
            }),
        ).toEqual([3, 4, 6]);
    });

    it('stands on top of its asset', () => {
        const top = wx.height ?? 4;
        expect(
            shapeOrigin(def, {
                id: 's',
                kind: 'vector',
                asset: 'wx',
                height: 2,
            }),
        ).toEqual([...(wx.position ?? [0, 0]), top + 2]);
    });

    it('has nowhere to stand without either', () => {
        expect(
            shapeOrigin(def, { id: 's', kind: 'vector', asset: 'ghost' }),
        ).toBeNull();
    });
});

describe('compileShape', () => {
    const wind: ShapeDef = {
        id: 'wind',
        kind: 'vector',
        position: [0, 0],
        heading: '{wx.windDeg} + 180',
        length: '{wx.windMs} * 5',
    };

    it('follows readings, wrapping the heading past north', () => {
        const shape = compileShape(def, wind)!;
        expect(shape.colour).toBe('#7fd1ff');
        expect(shape.pose({ 'wx.windDeg': 225, 'wx.windMs': 4 })).toEqual({
            headingDeg: 45,
            tiltDeg: 0,
            length: 20,
        });
    });

    it('has no pose while a reading is missing', () => {
        expect(compileShape(def, wind)!.pose({ 'wx.windDeg': 225 })).toBeNull();
    });

    it('keeps tilt to straight up or down, and length to nothing at least', () => {
        const shape = compileShape(def, {
            ...wind,
            tilt: '120',
            length: '-3',
            heading: '-90',
            color: '#ff0000',
        })!;
        expect(shape.colour).toBe('#ff0000');
        expect(shape.pose({})).toEqual({
            headingDeg: 270,
            tiltDeg: 90,
            length: 0,
        });
    });

    it('reaches a fan out to its radius', () => {
        const fan = compileShape(def, {
            id: 'f',
            kind: 'fan',
            position: [0, 0],
            radius: 40,
        })!;
        expect(fan.colour).toBe('#ffd166');
        expect(fan.pose({})).toEqual({ headingDeg: 0, tiltDeg: 0, length: 40 });
    });
});

describe('vectorTip', () => {
    it('points along its heading and tilt', () => {
        close(
            vectorTip([1, 2, 3], { headingDeg: 90, tiltDeg: 0, length: 10 }),
            [11, 2, 3],
        );
        close(
            vectorTip([0, 0, 0], { headingDeg: 0, tiltDeg: 90, length: 5 }),
            [0, 0, 5],
        );
    });
});

describe('fanMesh', () => {
    const points = (m: { positions: number[] }) =>
        Array.from({ length: m.positions.length / 3 }, (_, i) =>
            m.positions.slice(i * 3, i * 3 + 3),
        );

    it('makes a flat fan a slice of a disc, around north', () => {
        const mesh = fanMesh(10, 90, 0, 45);
        const p = points(mesh);
        expect(p).toHaveLength(4); // the centre and three on the rim
        close(p[1]!, [-10 * Math.SQRT1_2, 10 * Math.SQRT1_2, 0]);
        close(p[2]!, [0, 10, 0]);
        expect(mesh.indices).toEqual([0, 1, 2, 0, 2, 3]);
        // The rim, then both sides back to the centre.
        expect(mesh.edges).toEqual([1, 2, 2, 3, 0, 1, 0, 3]);
    });

    it('leaves a full circle without sides', () => {
        expect(fanMesh(10, 360, 0, 90).edges).toEqual([1, 2, 2, 3, 3, 4, 4, 5]);
    });

    it('closes a fan with height by its shell, top, bottom and sides', () => {
        const mesh = fanMesh(10, 90, 30, 45);
        const p = points(mesh);
        // The centre, and a 3 by 2 grid out at the radius.
        expect(p).toHaveLength(7);
        for (const q of p.slice(1)) expect(Math.hypot(...q)).toBeCloseTo(10);
        expect(Math.max(...p.map((q) => q[2]!))).toBeCloseTo(
            10 * Math.sin(Math.PI / 12),
        );
        // The shell's 2 quads make 4 triangles; top and bottom 2 each; the sides 1 each.
        expect(mesh.indices).toHaveLength(10 * 3);
        expect(mesh.indices.every((i) => i < p.length)).toBe(true);
    });
});

import { describe, expect, it } from 'vitest';
import {
    captureDates,
    hasUploadedLayers,
    hiddenAt,
    imageryTiles,
    parseImageryInfo,
    vectorStyle,
} from './layers.ts';
import type { TwinDefinition } from './types.ts';

describe('imagery', () => {
    it('reads what meridian-imagery writes', () => {
        expect(
            parseImageryInfo({
                format: 'png',
                minZoom: 10,
                maxZoom: 18,
                bounds: [174.74, -36.86, 174.76, -36.85],
            }),
        ).toEqual({
            minZoom: 10,
            maxZoom: 18,
            bounds: [174.74, -36.86, 174.76, -36.85],
        });
    });

    it.each([
        null,
        'x',
        { minZoom: 1.5, maxZoom: 2, bounds: [0, 0, 1, 1] },
        { minZoom: -1, maxZoom: 2, bounds: [0, 0, 1, 1] },
        { minZoom: 3, maxZoom: 2, bounds: [0, 0, 1, 1] },
        { minZoom: 1, maxZoom: 2, bounds: [0, 0, 1] },
        { minZoom: 1, maxZoom: 2, bounds: [0, 0, 1, 'n'] },
        { minZoom: 1, maxZoom: 2, bounds: [1, 0, 0, 1] },
        { minZoom: 1, maxZoom: 2, bounds: [0, 1, 1, 0] },
    ])('refuses %j', (raw) => {
        expect(parseImageryInfo(raw)).toBeNull();
    });

    it('finds the tiles beside imagery.json', () => {
        expect(imageryTiles('/assets/p/a/files/imagery.json')).toBe(
            '/assets/p/a/files/{z}/{x}/{y}.png',
        );
        expect(imageryTiles('/assets/p/a/files/imagery.json?v=2')).toBe(
            '/assets/p/a/files/{z}/{x}/{y}.png',
        );
    });
});

describe('map layers', () => {
    it('style with defaults, keeping the fill between 0 and 1', () => {
        expect(vectorStyle({})).toEqual({
            color: '#e9b949',
            fillOpacity: 0.25,
        });
        expect(vectorStyle({ color: '#ff0000', opacity: 4 })).toEqual({
            color: '#ff0000',
            fillOpacity: 1,
        });
        expect(vectorStyle({ opacity: -1 }).fillOpacity).toBe(0);
    });

    it('count tilesets, map layers and imagery as uploaded layers', () => {
        const def = (extra: Partial<TwinDefinition>) =>
            ({ ...extra }) as TwinDefinition;
        expect(hasUploadedLayers(undefined)).toBe(false);
        expect(hasUploadedLayers(def({}))).toBe(false);
        expect(
            hasUploadedLayers(
                def({ vectors: [{ id: 'a', name: 'A', url: 'u' }] }),
            ),
        ).toBe(true);
        expect(
            hasUploadedLayers(
                def({ imagery: [{ id: 'a', name: 'A', url: 'u' }] }),
            ),
        ).toBe(true);
    });
});

describe('the site on a date', () => {
    const def = {
        tilesets: [
            { id: 'march', name: 'March', url: 'a', assetId: 'scan-3' },
            { id: 'may', name: 'May', url: 'b', assetId: 'scan-5' },
            { id: 'mesh', name: 'Mesh', url: 'c', assetId: 'mesh-4' },
            { id: 'context', name: 'City', url: 'd', assetId: 'city' },
            { id: 'linked', name: 'Linked', url: 'e' },
        ],
        imagery: [
            { id: 'ortho-3', name: 'Ortho', url: 'f', assetId: 'ortho-3' },
            { id: 'ortho-5', name: 'Ortho', url: 'g', assetId: 'ortho-5' },
        ],
        models: [
            {
                id: 'shed',
                name: 'Shed',
                url: 'h',
                assetId: 'shed',
                placement: {
                    east: 0,
                    north: 0,
                    up: 0,
                    headingDeg: 0,
                    scale: 1,
                },
            },
        ],
        vectors: [{ id: 'plan', name: 'Plan', url: 'i', assetId: 'plan' }],
    } as unknown as TwinDefinition;
    const captures = {
        'scan-3': { on: '2026-03-10', kind: 'pointcloud' },
        'scan-5': { on: '2026-05-12', kind: 'pointcloud' },
        'mesh-4': { on: '2026-04-02', kind: 'mesh' },
        'ortho-3': { on: '2026-03-10', kind: 'imagery' },
        'ortho-5': { on: '2026-05-12', kind: 'imagery' },
        shed: { on: '2026-04-02', kind: 'bim' },
        plan: { on: '2026-03-10', kind: 'vector' },
        'not-in-the-twin': { on: '2025-01-01', kind: 'terrain' },
    };
    const hidden = (date: string | null) =>
        [...hiddenAt(def, captures, date)].sort();

    it('lists the dates its layers were captured, oldest first', () => {
        expect(captureDates(def, captures)).toEqual([
            '2026-03-10',
            '2026-04-02',
            '2026-05-12',
        ]);
        expect(captureDates(def, {})).toEqual([]);
        expect(captureDates(undefined, captures)).toEqual([]);
    });

    it('shows each kind of capture as of the date, and models from their date on', () => {
        expect(hidden('2026-03-10')).toEqual([
            'may',
            'mesh',
            'ortho-5',
            'shed',
        ]);
        expect(hidden('2026-04-30')).toEqual(['may', 'ortho-5']);
        expect(hidden('2026-05-12')).toEqual(['march', 'ortho-3']);
    });

    it('shows the latest of each kind now, and nothing dated before the first capture', () => {
        expect(hidden(null)).toEqual(['march', 'ortho-3']);
        expect(hidden('2026-01-01')).toEqual([
            'march',
            'may',
            'mesh',
            'ortho-3',
            'ortho-5',
            'plan',
            'shed',
        ]);
        expect(hiddenAt(undefined, captures, null).size).toBe(0);
    });
});

import { describe, expect, it } from 'vitest';
import {
    hasUploadedLayers,
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

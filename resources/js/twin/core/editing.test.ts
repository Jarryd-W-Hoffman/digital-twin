import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    fieldsFor,
    formatPoints,
    newItem,
    parsePoints,
    replaceItem,
    setField,
    uniqueId,
} from './editing.ts';
import type { TwinDefinition } from './types.ts';
import { validateDefinition } from './validate.ts';

const sample = (): TwinDefinition =>
    JSON.parse(
        readFileSync(
            new URL(
                '../../../../public/samples/harbour-depot.json',
                import.meta.url,
            ),
            'utf8',
        ),
    ) as TwinDefinition;

describe('fields from the schema', () => {
    it('gives a zone its id, name and footprint', () => {
        expect(fieldsFor('zones', {}).map((f) => [f.key, f.widget])).toEqual([
            ['id', 'id'],
            ['name', 'text'],
            ['footprint', 'polygon'],
        ]);
    });

    it('shows the fields for an asset’s kind only', () => {
        const keys = (kind: string) =>
            fieldsFor('assets', { kind }).map((f) => f.key);
        expect(keys('building')).not.toContain('route');
        expect(keys('building')).not.toContain('type');
        expect(keys('vehicle')).toContain('route');
        expect(keys('custom')).toContain('type');
        expect(keys('chiller')).toContain('cools');
        expect(keys('building')).toEqual(
            expect.arrayContaining([
                'id',
                'name',
                'kind',
                'zone',
                'footprint',
                'params',
            ]),
        );
    });

    it('lists the kinds to choose from, and which assets a chiller cools', () => {
        const fields = fieldsFor('assets', { kind: 'chiller' });
        expect(fields.find((f) => f.key === 'kind')?.options).toContain(
            'coldstore',
        );
        expect(fields.find((f) => f.key === 'cools')?.ofKind).toBe('coldstore');
    });
});

describe('edits', () => {
    it('removes a field that is emptied', () => {
        expect(setField({ id: 'a', zone: 'yard' }, 'zone', '')).toEqual({
            id: 'a',
        });
        expect(setField({ id: 'a' }, 'params', {})).toEqual({ id: 'a' });
        expect(setField({ id: 'a' }, 'cools', [])).toEqual({ id: 'a' });
        expect(setField({ id: 'a' }, 'height', 6)).toEqual({
            id: 'a',
            height: 6,
        });
    });

    it('replaces and removes items without touching the original', () => {
        const def = sample();
        const zone = def.zones[0];
        const renamed = replaceItem(def, 'zones', 0, {
            ...zone,
            name: 'Renamed',
        });
        expect(renamed.zones[0].name).toBe('Renamed');
        expect(def.zones[0].name).toBe(zone.name);
        expect(replaceItem(def, 'zones', 0, null).zones).toHaveLength(
            def.zones.length - 1,
        );
    });

    it('reads and writes footprints a line per point', () => {
        expect(
            formatPoints([
                [0, 1],
                [2.5, -3],
            ]),
        ).toBe('0, 1\n2.5, -3');
        expect(formatPoints(undefined)).toBe('');
        expect(parsePoints('0, 1\n 2.5 -3 \n\n')).toEqual([
            [0, 1],
            [2.5, -3],
        ]);
        expect(parsePoints('0, 1\n2')).toBeNull();
        expect(parsePoints('0, x')).toBeNull();
    });

    it('picks an id that isn’t taken', () => {
        expect(uniqueId('zone', ['yard'])).toBe('zone');
        expect(uniqueId('zone', ['zone', 'zone-2'])).toBe('zone-3');
    });

    it('adds zones and buildings the definition accepts', () => {
        let def = sample();
        for (const list of ['zones', 'assets', 'zones'] as const) {
            def = {
                ...def,
                [list]: [...def[list], newItem(def, list)],
            } as TwinDefinition;
        }
        expect(validateDefinition(def)).toEqual([]);
        expect(def.zones.at(-1)?.id).toBe('zone-2');
        expect(def.assets.at(-1)).toMatchObject({
            kind: 'building',
            fedBy: 'board',
        });
    });
});

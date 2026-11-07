import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    LISTS,
    choicesFor,
    editNote,
    elementNode,
    fieldsFor,
    fieldsOf,
    itemLabel,
    itemsOf,
    formatPoints,
    newElement,
    newItem,
    parsePoints,
    replaceItem,
    setField,
    uniqueId,
} from './editing.ts';
import type { TwinDefinition } from './types.ts';
import { validateDefinition } from './validate.ts';

const sample = (name = 'harbour-depot'): TwinDefinition =>
    JSON.parse(
        readFileSync(
            new URL(`../../../../public/samples/${name}.json`, import.meta.url),
            'utf8',
        ),
    ) as TwinDefinition;
const keys = (fields: { key: string }[]) => fields.map((f) => f.key);

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

    it('offers every asset a feature of a model or tileset, last', () => {
        const fields = fieldsFor('assets', { kind: 'bus' });
        expect(fields.at(-1)).toMatchObject({
            key: 'feature',
            widget: 'feature',
            title: 'Model feature',
        });
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
        expect(setField({ id: 'r' }, 'stops', [], true)).toEqual({
            id: 'r',
            stops: [],
        });
        expect(setField({ key: 'n' }, 'unit', '', true)).toEqual({
            key: 'n',
            unit: '',
        });
        expect(
            setField({ key: 'n', unit: '' }, 'unit', undefined, true),
        ).toEqual({ key: 'n' });
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

    it('adds an item to every list that the definition accepts', () => {
        let def = sample();
        for (const list of [
            ...LISTS.map((l) => l.list),
            'zones',
            'assets',
        ] as const) {
            def = replaceItem(
                def,
                list,
                itemsOf(def, list).length,
                newItem(def, list),
            );
        }
        expect(validateDefinition(def)).toEqual([]);
        expect(def.zones.at(-1)?.id).toBe('zone-2');
        expect(def.assets.at(-1)).toMatchObject({
            kind: 'building',
            fedBy: 'board',
        });
        expect(def.rules.at(-1)).toMatchObject({
            id: 'rule',
            asset: def.assets[0].id,
        });
        expect(def.zones.at(-1)?.id).toBe('zone-2');
    });

    it('starts a rule on every asset of a kind when there are no assets', () => {
        expect(newItem({ ...sample(), assets: [] }, 'rules')).toMatchObject({
            kind: 'building',
        });
    });

    it('labels items by name, title or label, then id', () => {
        expect(itemLabel({ id: 'a', name: 'A' })).toBe('A');
        expect(itemLabel({ title: 'Fire' })).toBe('Fire');
        expect(itemLabel({ label: 'Load', value: '0' })).toBe('Load');
        expect(itemLabel({ id: 'x', name: '' })).toBe('x');
        expect(itemLabel({})).toBe('?');
    });

    it('notes which lists a save changed', () => {
        const def = sample();
        expect(editNote(def, def)).toBe('Edited the definition');
        expect(editNote(def, { ...def, zones: [] })).toBe('Edited zones');
        expect(editNote(def, { ...def, zones: [], rules: [], kpis: [] })).toBe(
            'Edited zones, rules and KPIs',
        );
    });
});

describe('nested fields', () => {
    it('orders a rule’s fields, including those only for threshold rules', () => {
        expect(keys(fieldsFor('rules', {}))).toEqual([
            'id',
            'name',
            'severity',
            'kind',
            'asset',
            'metric',
            'op',
            'threshold',
            'change',
            'forS',
            'clearAt',
            'when',
            'clearWhen',
            'message',
        ]);
    });

    it('describes a type’s metrics, states and transitions as lists', () => {
        const fields = fieldsFor('types', {});
        expect(keys(fields)).toEqual([
            'id',
            'name',
            'primary',
            'metrics',
            'states',
            'transitions',
            'visual',
        ]);
        const metrics = fields.find((f) => f.key === 'metrics');
        expect(metrics?.widget).toBe('list');
        expect(keys(fieldsOf(elementNode(metrics!), {}))).toEqual([
            'key',
            'label',
            'unit',
            'decimals',
            'default',
            'expr',
            'setpoint',
        ]);
        expect(newElement(metrics!)).toEqual({ key: 'value', unit: '' });
        const states = fieldsOf(
            elementNode(fields.find((f) => f.key === 'states')!),
            {},
        );
        expect(states.find((f) => f.key === 'status')?.options).toContain(
            'maintenance',
        );
        const visual = fields.find((f) => f.key === 'visual')!;
        expect(keys(fieldsOf(visual.node, {}))).toEqual([
            'color',
            'shape',
            'height',
        ]);
    });

    it('keeps a route’s stops and a scenario’s faults when emptied', () => {
        expect(
            fieldsFor('routes', {}).find((f) => f.key === 'stops')?.keepEmpty,
        ).toBe(true);
        expect(
            fieldsFor('scenarios', {}).find((f) => f.key === 'faults')
                ?.keepEmpty,
        ).toBe(true);
        expect(
            fieldsFor('zones', {}).find((f) => f.key === 'footprint')
                ?.keepEmpty,
        ).toBe(false);
    });

    it('gives KPIs and panels their figures', () => {
        expect(keys(fieldsFor('kpis', {}))).toEqual([
            'label',
            'value',
            'unit',
            'decimals',
        ]);
        const panel = fieldsFor('panels', {});
        expect(keys(panel)).toEqual(['title', 'items', 'chart']);
        expect(keys(fieldsOf(panel[2].node, {}))).toEqual([
            'value',
            'unit',
            'decimals',
            'label',
        ]);
    });
});

describe('pickers', () => {
    const def = sample('northshore');
    const field = (
        list: 'rules' | 'routes' | 'types',
        key: string,
        item = {},
    ) => fieldsFor(list, item).find((f) => f.key === key)!;

    it('offers the definition’s zones, routes, types and assets', () => {
        const zone = fieldsFor('assets', {}).find((f) => f.key === 'zone')!;
        expect(choicesFor(zone, def, {}, {})).toEqual(
            def.zones.map((z) => z.id),
        );
        const asset = field('rules', 'asset');
        expect(choicesFor(asset, def, {}, {})).toHaveLength(def.assets.length);
        expect(choicesFor(field('rules', 'severity'), def, {}, {})).toEqual([
            'info',
            'warning',
            'critical',
        ]);
    });

    it('offers only chargers for a stop, and never the asset itself', () => {
        const charger = fieldsOf(
            elementNode(field('routes', 'stops')),
            {},
        ).find((f) => f.key === 'charger')!;
        const chargers = def.assets
            .filter((a) => a.kind === 'charger')
            .map((a) => a.id);
        expect(choicesFor(charger, def, {}, {})).toEqual(chargers);
        expect(choicesFor(charger, def, { id: chargers[0] }, {})).toEqual(
            chargers.slice(1),
        );
    });

    it('offers the metrics of a rule’s asset, or of its kind', () => {
        const metric = field('rules', 'metric');
        expect(choicesFor(metric, def, {}, { asset: 'freezer' })).toContain(
            'tempC',
        );
        expect(choicesFor(metric, def, {}, { kind: 'coldstore' })).toContain(
            'excursionMin',
        );
        expect(choicesFor(metric, def, {}, {})).toEqual([]);
    });

    it('offers a type its own metrics and states', () => {
        const type = def.types!.find((t) => (t.states ?? []).length > 0)!;
        const owner = type as unknown as Record<string, unknown>;
        expect(
            choicesFor(field('types', 'primary'), def, owner, owner),
        ).toEqual(type.metrics.map((m) => m.key));
        const transitions = fieldsOf(
            elementNode(field('types', 'transitions')),
            {},
        );
        const ids = type.states!.map((s) => s.id);
        expect(
            choicesFor(
                transitions.find((f) => f.key === 'to')!,
                def,
                owner,
                {},
            ),
        ).toEqual(ids);
        expect(
            choicesFor(
                transitions.find((f) => f.key === 'from')!,
                def,
                owner,
                {},
            ),
        ).toEqual(['*', ...ids]);
        expect(choicesFor(field('types', 'primary'), def, {}, {})).toEqual([]);
    });
});

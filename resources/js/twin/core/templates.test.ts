import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { advance, compile, initialState } from './engine.ts';
import { parseDefinition, validateDefinition } from './validate.ts';

/**
 * The templates a new twin starts from (resources/templates), as the control
 * plane places them: at an origin, with its UTC offset.
 */
const index = JSON.parse(
    readFileSync('resources/templates/index.json', 'utf8'),
) as { id: string }[];

const placed = (id: string): unknown => ({
    ...JSON.parse(readFileSync(`resources/templates/${id}.json`, 'utf8')),
    origin: { lon: 174.76, lat: -36.85 },
    utcOffsetHours: 12,
});

describe.each(index.map((t) => t.id))('the %s template', (id) => {
    it('passes the browser validator', () => {
        expect(validateDefinition(placed(id))).toEqual([]);
    });

    it('runs a day with nothing that is not a number', () => {
        const model = compile(parseDefinition(placed(id)));
        const state = advance(
            model,
            initialState(model, Date.UTC(2026, 10, 5)),
            24 * 3600,
        );

        expect(
            Object.entries(state.values).filter(([, v]) => !Number.isFinite(v)),
        ).toEqual([]);
    });
});

describe('templates without power', () => {
    it('model no electricity', () => {
        for (const id of ['empty', 'factory', 'mine', 'survey']) {
            const model = compile(parseDefinition(placed(id)));
            expect(model.mainBusId).toBeUndefined();
        }
    });

    it('keep a factory line running and a mine stockpile filling', () => {
        const run = (id: string, s: number) => {
            const model = compile(parseDefinition(placed(id)));
            return advance(model, initialState(model, Date.UTC(2026, 10, 5)), s)
                .values;
        };
        const factory = run('factory', 3600);
        expect(factory['cell-1.partsPerHour']).toBe(75);
        expect(factory['cell-1.partsTotal']).toBeGreaterThan(70);
        expect(factory['packer.boxesTotal']).toBeGreaterThan(10);

        const mine = run('mine', 3600);
        expect(mine['rom.tonnes']).toBeCloseTo(18200, -1);
        expect(mine['sump.levelM']).toBeGreaterThan(0);
    });
});

import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { advance, compile, initialState } from './engine.ts';
import { parseDefinition, validateDefinition } from './validate.ts';

/**
 * The control plane's blank twin (app/Support/BlankTwin.php), as its PHP test
 * pins it in tests/fixtures/blank-twin.json.
 */
const raw: unknown = JSON.parse(
    readFileSync('tests/fixtures/blank-twin.json', 'utf8'),
);

describe('blank twin', () => {
    it('passes the browser validator', () => {
        expect(validateDefinition(raw)).toEqual([]);
    });

    it('runs a day with nothing on the board', () => {
        const model = compile(parseDefinition(raw));
        const state = advance(
            model,
            initialState(model, Date.UTC(2026, 10, 5)),
            24 * 3600,
        );

        expect(
            Object.entries(state.values).filter(([, v]) => !Number.isFinite(v)),
        ).toEqual([]);
        expect(state.values['site.loadKw']).toBe(0);
    });
});

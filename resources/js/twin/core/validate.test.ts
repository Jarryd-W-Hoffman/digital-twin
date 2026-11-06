import { describe, expect, it } from 'vitest';
import {
    definitionCases,
    definitionFor,
} from '../../../../tests/fixtures/definitionCases.ts';
import { validateDefinition } from './validate.ts';

describe('validateDefinition against the shared fixtures', () => {
    it.each(definitionCases)('$name', (c) => {
        expect(validateDefinition(definitionFor(c))).toEqual(c.errors);
    });
});

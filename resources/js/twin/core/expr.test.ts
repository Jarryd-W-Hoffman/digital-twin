import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    evaluate,
    expressionError,
    MAX_LENGTH,
    namesIn,
    parseExpression,
    type Scope,
} from './expr.ts';

const fixtures = JSON.parse(
    readFileSync(
        new URL(
            '../../../../tests/fixtures/expression-cases.json',
            import.meta.url,
        ),
        'utf8',
    ),
) as {
    scope: Record<string, number>;
    cases: { src: string; value?: number | null; error?: string }[];
};

const scope: Scope = {
    name: (name) => fixtures.scope[name],
    ref: (asset, metric) => fixtures.scope[`{${asset}.${metric}}`],
};

describe('expressions against the shared fixtures', () => {
    it.each(fixtures.cases)('$src', (c) => {
        expect(expressionError(c.src)).toBe(c.error ?? null);
        if (c.error === undefined) {
            const value = evaluate(parseExpression(c.src), scope);
            if (c.value === null) expect(value).toBeNaN();
            else expect(value).toBeCloseTo(c.value!, 10);
        }
    });
});

describe('expressions', () => {
    it('refuses anything that is not text, or is too long', () => {
        expect(expressionError(3)).toBe('must be text');
        expect(expressionError('1+'.repeat(MAX_LENGTH))).toBe(
            `longer than ${MAX_LENGTH} characters`,
        );
    });

    it('lists the names and references it reads', () => {
        expect(
            namesIn(
                parseExpression(
                    '!idle && -levelPct < max({fire-tank.levelPct}, 1) ? flowLps : levelPct',
                ),
            ),
        ).toEqual({
            names: ['idle', 'levelPct', 'flowLps'],
            refs: [{ asset: 'fire-tank', metric: 'levelPct' }],
        });
    });

    it('short-circuits, so a missing value on the other side does not matter', () => {
        expect(evaluate(parseExpression('idle && ghost'), scope)).toBe(0);
        expect(evaluate(parseExpression('running || ghost'), scope)).toBe(1);
        expect(evaluate(parseExpression('idle && running'), scope)).toBe(0);
        expect(evaluate(parseExpression('idle || running'), scope)).toBe(1);
    });

    it('compares every way', () => {
        const run = (src: string) => evaluate(parseExpression(src), scope);
        expect([
            run('1 < 2'),
            run('2 <= 2'),
            run('3 > 2'),
            run('2 >= 3'),
            run('2 != 2'),
            run('2 == 2'),
            run('min(4, 2)'),
        ]).toEqual([1, 1, 1, 0, 0, 1, 2]);
    });

    it('lets errors other than a missing value through', () => {
        const throwing: Scope = {
            name: () => {
                throw new Error('broken scope');
            },
            ref: () => 0,
        };
        expect(() => evaluate(parseExpression('a'), throwing)).toThrow(
            'broken scope',
        );
    });
});

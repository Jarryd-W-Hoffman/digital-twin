import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { CURRENT_FORMAT, migrateDefinition } from './format.ts';

const migrations = JSON.parse(
    readFileSync(
        new URL(
            '../../../../tests/fixtures/definition-migrations.json',
            import.meta.url,
        ),
        'utf8',
    ),
) as { cases: { name: string; from: unknown; to: unknown }[] };

describe('definition formats', () => {
    it.each(migrations.cases.map((c) => [c.name, c.from, c.to] as const))(
        '%s, as the control plane does',
        (_name, from, to) => {
            expect(migrateDefinition(from)).toStrictEqual(to);
        },
    );

    it('writes the newest format it reads', () => {
        expect(CURRENT_FORMAT).toBe(2);
    });
});

import { readFileSync } from 'node:fs';

type Op = { set: string; value: unknown } | { unset: string } | { push: string; value: unknown };
export interface DefinitionCase {
  name: string;
  replace?: unknown;
  ops?: Op[];
  errors: string[];
}

const fixtures = JSON.parse(readFileSync(new URL('./definition-cases.json', import.meta.url), 'utf8')) as {
  base: string;
  cases: DefinitionCase[];
};

/** Applies a case's edits to a fresh copy of the base sample, as the PHP tests do. */
export function definitionFor(c: DefinitionCase): unknown {
  if ('replace' in c) return c.replace;
  const def: unknown = JSON.parse(readFileSync(new URL(`../../public/samples/${fixtures.base}.json`, import.meta.url), 'utf8'));
  for (const op of c.ops ?? []) {
    const path = ('set' in op ? op.set : 'unset' in op ? op.unset : op.push).split('.');
    const last = path.pop() as string;
    let node = def as Record<string, unknown>;
    for (const key of path) node = node[key] as Record<string, unknown>;
    if ('set' in op) node[last] = op.value;
    else if ('unset' in op) delete node[last];
    else (node[last] as unknown[]).push(op.value);
  }
  return def;
}

export const definitionCases = fixtures.cases;

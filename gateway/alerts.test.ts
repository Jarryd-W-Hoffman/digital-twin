import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { TwinDefinition } from '../resources/js/twin/core/types.ts';
import { AlertWatch, HEARTBEAT_MS, RETRY_MS } from './alerts.ts';

const sample = JSON.parse(readFileSync('public/samples/harbour-depot.json', 'utf8')) as TwinDefinition;
const definition: TwinDefinition = {
  ...sample,
  rules: [{ id: 'warm', name: 'Warm', severity: 'critical', kind: 'coldstore', metric: 'tempC', op: 'gt', threshold: 5, clearAt: 4, message: '{asset} is warm' }],
};
const cp = { url: 'http://cp.test/', token: 'mgw_t' };
const frame = (t: number, tempC: number) => ({ t, values: { 'store.tempC': tempC } });

/** Stands in for fetch, answering with each status in turn (200 once they run out). */
function fakeFetch(...statuses: number[]) {
  const bodies: unknown[] = [];
  const fetch = vi.fn(async (url: string, init: RequestInit) => {
    expect(url).toBe('http://cp.test/api/gateway/alerts');
    expect((init.headers as Record<string, string>).authorization).toBe('Bearer mgw_t');
    bodies.push(JSON.parse(init.body as string));
    return new Response(null, { status: statuses.shift() ?? 200 });
  });
  vi.stubGlobal('fetch', fetch);
  return bodies;
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('alert watch', () => {
  it('sends the open alerts when they change, then only on the heartbeat', async () => {
    const bodies = fakeFetch();
    const watch = new AlertWatch(cp, definition);
    await watch.report(0);
    expect(bodies).toEqual([{ open: [] }]);

    watch.evaluate(frame(0, 7));
    await watch.report(1000);
    expect(bodies[1]).toEqual({ open: [{ rule: 'warm', asset: 'store', severity: 'critical', message: 'Seafood store is warm', value: 7 }] });

    watch.evaluate(frame(5000, 6));
    await watch.report(2000);
    expect(bodies).toHaveLength(2);
    await watch.report(1000 + HEARTBEAT_MS);
    expect(bodies).toHaveLength(3);

    watch.evaluate(frame(10_000, 3));
    await watch.report(1001 + HEARTBEAT_MS);
    expect(bodies[3]).toEqual({ open: [] });
    expect(watch.sent).toBe(4);
  });

  it('keeps a change through a failure, and waits before trying again', async () => {
    vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const bodies = fakeFetch(500);
    const watch = new AlertWatch(cp, definition);
    watch.evaluate(frame(0, 7));
    await watch.report(0);
    expect(watch.failed).toBe(1);
    await watch.report(RETRY_MS - 1);
    expect(bodies).toHaveLength(1);
    await watch.report(RETRY_MS);
    expect(bodies).toHaveLength(2);
    expect(bodies[1]).toMatchObject({ open: [{ rule: 'warm' }] });
    expect(watch.sent).toBe(1);
  });

  it('starts again on a new definition, and sends what that leaves open', async () => {
    const bodies = fakeFetch();
    const watch = new AlertWatch(cp, definition);
    watch.evaluate(frame(0, 7));
    await watch.report(0);
    watch.reset({ ...definition, rules: [] });
    watch.evaluate(frame(5000, 7));
    await watch.report(1);
    expect(bodies[1]).toEqual({ open: [] });
  });

  it('sends one request at a time', async () => {
    const bodies = fakeFetch();
    const watch = new AlertWatch(cp, definition);
    const first = watch.report(0);
    watch.evaluate(frame(0, 7));
    expect(watch.report(1)).toBe(first);
    await first;
    await watch.report(2);
    expect(bodies).toHaveLength(2);
  });

  it('only watches without a control plane', async () => {
    const bodies = fakeFetch();
    const watch = new AlertWatch(null, definition);
    expect(watch.evaluate(frame(0, 7)).raised).toHaveLength(1);
    await watch.report(0);
    expect(bodies).toEqual([]);
    expect(watch.open()).toHaveLength(1);
  });
});

import { expect, test, type Page } from '@playwright/test';

/** These drive the real app in Chrome with real WebGL, through the real Cesium canvas. */

const GATEWAY = 'http://127.0.0.1:8799';

interface Debug {
  __twin: { getState(): Record<string, never> & { view?: { t: number; values: Record<string, number> }; selectedId: string | null; alerts: unknown[]; simState?: { faults: unknown[] }; replayT: number | null; live?: { t: number } } };
  __scene: { appliedColor(id: string): string | null; project(id: string): { x: number; y: number } | null; viewer: { scene: { primitives: { length: number } } } };
}

const value = (page: Page, key: string) =>
  page.evaluate((k) => (window as unknown as Debug).__twin.getState().view?.values[k], key);

const color = (page: Page, id: string) =>
  page.evaluate((a) => (window as unknown as Debug).__scene.appliedColor(a), id);

async function open(page: Page, query = ''): Promise<string[]> {
  const problems: string[] = [];
  page.on('pageerror', (e) => problems.push(e.message));
  page.on('console', (m) => {
    if (m.type() === 'error') problems.push(m.text());
  });
  await page.goto(`/demo${query}`);
  await expect(page.locator('canvas').first()).toBeVisible({ timeout: 45_000 });
  await expect.poll(() => color(page, page.url().includes('harbour') ? 'store' : 'freezer'), { timeout: 45_000 }).not.toBeNull();
  return problems;
}

test('renders the twin with no errors and no third-party requests', async ({ page }) => {
  const hosts = new Set<string>();
  page.on('request', (r) => hosts.add(new URL(r.url()).host));
  const problems = await open(page);
  await expect(page.getByRole('heading', { name: 'Northshore Cold Chain Hub' })).toBeVisible();
  await expect(page.locator('.kpi', { hasText: 'Site load' })).toContainText('kW');
  await expect(page.getByRole('region', { name: 'Fire protection' })).toContainText('Tank level');
  await page.waitForTimeout(2000);
  expect(problems).toEqual([]);
  expect([...hosts].filter((h) => !h.startsWith('127.0.0.1'))).toEqual([]);
});

test('the live model advances, and pause holds it', async ({ page }) => {
  await open(page);
  const t = () => page.evaluate(() => (window as unknown as Debug).__twin.getState().live!.t);
  const first = await t();
  await expect.poll(t).toBeGreaterThan(first);
  await page.getByRole('button', { name: 'Pause' }).click();
  await page.waitForTimeout(400);
  const held = await t();
  await page.waitForTimeout(1200);
  expect(await t()).toBe(held);
  await page.getByRole('button', { name: 'Resume' }).click();
  await expect.poll(t).toBeGreaterThan(held);
});

test('clicking a building on the canvas selects it', async ({ page }) => {
  await open(page);
  await page.waitForTimeout(5000); // Let the opening flight down from orbit settle.
  const at = await page.evaluate(() => (window as unknown as Debug).__scene.project('chillroom'));
  expect(at).not.toBeNull();
  await page.mouse.click(at!.x, at!.y);
  await expect(page.getByRole('heading', { name: 'Chill room', level: 2 })).toBeVisible();
  await expect(page.getByText('Temperature', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Chiller 3' }).last()).toBeVisible();
});

test('the asset tree selects, searches and navigates connections', async ({ page }) => {
  await open(page);
  await page.getByLabel('Find an asset').fill('chiller');
  await expect(page.locator('.asset-row')).toHaveCount(3);
  await page.locator('.asset-row', { hasText: 'Chiller 2' }).click();
  await expect(page.getByRole('heading', { name: 'Chiller 2' })).toBeVisible();
  await page.getByRole('button', { name: 'Refrigeration board' }).click();
  await expect(page.getByRole('heading', { name: 'Refrigeration board' })).toBeVisible();
  await page.getByLabel('Find an asset').fill('zzz');
  await expect(page.getByText('No assets match')).toBeVisible();
});

test('commands reach the model', async ({ page }) => {
  await open(page);
  await page.locator('.asset-row', { hasText: 'Chill room' }).click();
  await page.getByRole('button', { name: 'Lower setpoint' }).click();
  await page.getByRole('button', { name: 'Lower setpoint' }).click();
  await page.getByRole('button', { name: 'Apply' }).click();
  await expect.poll(() => value(page, 'chillroom.setpointC')).toBe(2);

  await page.locator('.asset-row', { hasText: 'Chiller 3' }).click();
  await page.getByRole('button', { name: 'Take out of service' }).click();
  await expect.poll(() => value(page, 'ch-3.state')).toBe(3);
  await expect.poll(() => color(page, 'ch-3')).toBe('#5aa2e6');
});

test('what-if forecasts without touching live, then applies; the cascade shows everywhere', async ({ page }) => {
  await open(page);
  await page.getByRole('tab', { name: 'What-if' }).click();
  await page.getByRole('radio', { name: /Refrigeration board trips/ }).check();
  await page.getByRole('button', { name: 'Run forecast' }).click();
  const results = page.getByTestId('forecast-results');
  await expect(results).toContainText('Freezer hall is above its temperature limit');
  await expect(results.locator('.compare')).toContainText('Stock at risk');
  await expect(page.locator('.alert-pill')).toHaveText(/No alerts/);
  expect(await value(page, 'db-cold.state')).toBe(1);

  await page.getByRole('button', { name: 'Apply to the live twin' }).click();
  await expect.poll(() => value(page, 'db-cold.state')).toBe(4);
  await expect.poll(() => color(page, 'db-cold')).toBe('#ef5350');
  await expect.poll(() => value(page, 'ch-1.coolingKw')).toBe(0);
  await expect(page.locator('.alert-pill')).toHaveClass(/is-critical/);

  // Colour modes recolour the real geometry, right away.
  const status = await color(page, 'freezer');
  await page.getByRole('radio', { name: 'Power' }).click();
  await expect.poll(() => color(page, 'freezer')).not.toBe(status);

  // Alerts list it, and it clears when the fault does.
  await page.getByRole('tab', { name: 'Alerts' }).click();
  await expect(page.getByText('Refrigeration board has tripped')).toBeVisible();
  await page.getByRole('button', { name: 'Acknowledge' }).first().click();
  await expect(page.getByText('Acknowledged')).toBeVisible();
  await page.getByRole('tab', { name: 'What-if' }).click();
  await page.getByRole('button', { name: 'Clear', exact: true }).click();
  await expect.poll(() => value(page, 'db-cold.state')).toBe(1);
});

test('time travel shows the past, and returns to live', async ({ page }) => {
  await open(page);
  await page.getByRole('button', { name: 'Pause' }).click();
  const liveTemp = await value(page, 'site.solarKw');
  const slider = page.getByRole('slider', { name: 'Time' });
  const bounds = await slider.evaluate((el: HTMLInputElement) => ({ min: Number(el.min), max: Number(el.max) }));
  const past = bounds.min + (bounds.max - bounds.min) * 0.05;
  await slider.evaluate((el: HTMLInputElement, t) => {
    // React tracks the value property, so set it the way the browser does.
    Reflect.set(HTMLInputElement.prototype, 'value', String(t), el);
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }, past);
  await expect(page.locator('.clock')).toContainText('Replay');
  await expect(page.getByRole('button', { name: 'Back to live' })).toBeEnabled();
  // Early morning: little sun then, against a bright afternoon now.
  expect(await value(page, 'site.solarKw')).toBeLessThan(liveTemp!);
  await page.getByRole('button', { name: 'Back to live' }).click();
  await expect(page.locator('.clock')).toContainText('Site time');
  expect(await value(page, 'site.solarKw')).toBe(liveTemp);
});

test('a different definition runs the whole platform', async ({ page }) => {
  await open(page, '?twin=harbour-depot');
  await expect(page.getByRole('heading', { name: 'Harbour Depot' })).toBeVisible();
  await expect(page.locator('.asset-row')).toHaveCount(5);
  await expect(page.locator('.kpi', { hasText: 'Battery' })).toHaveCount(0);
  await expect(page.locator('.kpi')).toHaveCount(4);
  await expect(page.locator('.kpi', { hasText: 'Margin to limit' })).toContainText('°C');
  // The depot sits on its terrain's 12 m pad, not the bare ellipsoid.
  const terrain = () => page.evaluate(() => (window as unknown as { __scene: { terrain(): { loaded: boolean; ground: number } } }).__scene.terrain());
  await expect.poll(async () => (await terrain()).loaded, { timeout: 30_000 }).toBe(true);
  expect((await terrain()).ground).toBeCloseTo(12, 0);
  await page.getByRole('tab', { name: 'What-if' }).click();
  await page.getByRole('button', { name: 'Run forecast' }).click();
  await expect(page.getByTestId('forecast-results')).toContainText('Seafood store');
});

test('a bad definition file reports every problem and keeps the twin running', async ({ page }) => {
  await open(page);
  await page.getByTestId('twin-file').setInputFiles({
    name: 'bad.json',
    mimeType: 'application/json',
    buffer: Buffer.from(JSON.stringify({ id: 'x', name: 'X', origin: { lon: 999, lat: 0 }, assets: [] })),
  });
  const dialog = page.getByRole('alertdialog');
  await expect(dialog).toContainText('origin.lon');
  await expect(dialog).toContainText('exactly one main bus');
  await dialog.getByRole('button', { name: 'Keep the current twin' }).click();
  await expect(page.getByRole('heading', { name: 'Northshore Cold Chain Hub' })).toBeVisible();
});

test('an unknown twin says so', async ({ page }) => {
  await page.goto('/demo?twin=nope');
  await expect(page.getByRole('alertdialog')).toContainText('"nope" not found');
});

test('over a gateway: streams, commands, and real readings move the twin', async ({ page, request }) => {
  await open(page, `?gateway=${encodeURIComponent('ws://127.0.0.1:8799/ws')}`);
  await expect(page.locator('.source')).toContainText('Gateway');
  await page.locator('.asset-row', { hasText: 'Freezer hall' }).click();

  const res = await request.post(`${GATEWAY}/ingest`, {
    data: { asset: 'freezer', metric: 'tempC', value: -11.5, ttlS: 900 },
  });
  expect(res.status()).toBe(202);
  await expect.poll(() => value(page, 'freezer.tempC')).toBe(-11.5);
  await expect(page.locator('.measured')).toBeVisible();
  await expect(page.locator('.source')).toContainText('1 measured');
  // Rules run on any feed: this reading is over the limit.
  await expect(page.locator('.alert-pill')).toHaveClass(/is-critical/, { timeout: 15_000 });

  await page.locator('.asset-row', { hasText: 'Chiller 1' }).click();
  await page.getByRole('button', { name: 'Take out of service' }).click();
  await expect.poll(() => value(page, 'ch-1.state')).toBe(3);
  await page.getByRole('button', { name: 'Return to service' }).click();
  await expect.poll(() => value(page, 'ch-1.state')).not.toBe(3);
});

test('survives a gateway that is not there', async ({ page }) => {
  await page.goto(`/demo?gateway=${encodeURIComponent('ws://127.0.0.1:1/ws')}`);
  await expect(page.locator('.source-dot.is-offline, .source-dot.is-connecting')).toBeVisible();
  await expect(page.locator('.topbar')).toContainText('Connecting');
});

test('pulls back to the whole Earth and returns to the site', async ({ page }) => {
  await open(page);
  const height = () =>
    page.evaluate(() => (window as unknown as { __scene: { viewer: { camera: { positionCartographic: { height: number } } } } }).__scene.viewer.camera.positionCartographic.height);
  await expect.poll(height, { timeout: 15_000 }).toBeLessThan(5_000);
  await page.getByRole('button', { name: 'Globe' }).click();
  await expect.poll(height, { timeout: 15_000 }).toBeGreaterThan(10_000_000);
  await page.getByRole('button', { name: 'Whole site' }).click();
  await expect.poll(height, { timeout: 15_000 }).toBeLessThan(5_000);
});

test('works at phone width without horizontal scroll', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 780 });
  await open(page);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
  await page.getByRole('button', { name: 'Details' }).click();
  await expect(page.getByRole('tab', { name: 'What-if' })).toBeVisible();
});

test('measures distances, heights, areas and points in the scene, without selecting anything', async ({ page }) => {
  const problems = await open(page);
  // Clicks should land on the ground, not on the neighbouring buildings.
  await page.getByLabel('Neighbouring buildings').uncheck();
  await page.getByRole('button', { name: 'Whole site' }).click();
  await page.waitForTimeout(3000);
  const screen = (point: [number, number], height = 0) =>
    page.evaluate(
      ([p, h]) => (window as unknown as { __scene: { screenAt(p: [number, number], h: number): { x: number; y: number } } }).__scene.screenAt(p, h),
      [point, height] as const,
    );
  const clickAt = async (point: [number, number], height = 0) => {
    const at = await screen(point, height);
    const box = (await page.locator('.globe canvas').first().boundingBox())!;
    await page.mouse.click(box.x + at.x, box.y + at.y);
  };
  const readout = page.getByRole('region', { name: 'Measure' }).getByRole('definition');
  const reading = async (label: string) => {
    const row = page.getByRole('region', { name: 'Measure' }).locator('.measure-readout div', { has: page.getByText(label, { exact: true }) });
    return (await row.locator('dd').innerText()).replace(/,/g, '');
  };
  const metres = (text: string) => Number(/([\d.]+) m/.exec(text)![1]);

  await page.getByRole('button', { name: 'Measure' }).click();
  const panel = page.getByRole('region', { name: 'Measure' });
  await expect(panel.getByRole('button', { name: 'Distance' })).toHaveAttribute('aria-pressed', 'true');
  await expect(panel.getByText('Click two points.')).toBeVisible();

  // 30 m east and 40 m north: 50 m, all of it along the level.
  await clickAt([0, -170]);
  await clickAt([30, -130]);
  await expect(readout.first()).toBeVisible();
  expect(metres(await reading('Distance'))).toBeCloseTo(50, -0.5);
  expect(metres(await reading('Along the level'))).toBeCloseTo(50, -0.5);
  expect(metres(await reading('Up'))).toBeLessThan(0.5);

  // The freezer hall is 18 m tall.
  await panel.getByRole('button', { name: 'Height' }).click();
  await clickAt([-80, 30], 18);
  await expect(panel.getByText('Above the ground')).toBeVisible();
  expect(metres(await reading('Above the ground'))).toBeCloseTo(18, -0.5);

  // A right triangle of 40 m by 40 m: 800 m².
  await panel.getByRole('button', { name: 'Area' }).click();
  await clickAt([0, -170]);
  await clickAt([40, -170]);
  await clickAt([0, -130]);
  await expect(panel.getByText('Perimeter')).toBeVisible();
  const area = Number(/([\d.]+) m²/.exec(await reading('Area'))![1]);
  expect(area).toBeGreaterThan(760);
  expect(area).toBeLessThan(840);

  await panel.getByRole('button', { name: 'Point' }).click();
  await clickAt([0, -150]);
  await expect(panel.getByText('Latitude')).toBeVisible();
  expect(await reading('Latitude')).toMatch(/^−\d+\.\d{6}°$/);
  expect(await reading('Ground slope')).toBe('level');

  // Measuring never selected anything; Escape puts the tools away.
  expect(await page.evaluate(() => (window as unknown as Debug).__twin.getState().selectedId)).toBeNull();
  await page.keyboard.press('Escape');
  await expect(panel).toHaveCount(0);
  expect(problems).toEqual([]);
});

test('a section cuts the blocks and clips the neighbouring buildings, and flips', async ({ page }) => {
  const problems = await open(page);
  type Hooks = { __scene: { bodyTop(id: string): number | null; clipPlanes(layer: string): number } };
  const top = (id: string) => page.evaluate((a) => (window as unknown as Hooks).__scene.bodyTop(a), id);
  const planes = () => page.evaluate(() => (window as unknown as Hooks).__scene.clipPlanes('context'));
  await expect.poll(planes, { timeout: 30_000 }).toBe(0);
  expect(await top('freezer')).toBeCloseTo(18.5);

  await page.getByRole('button', { name: 'Section' }).click();
  const panel = page.getByRole('region', { name: 'Section' });
  // A new section cuts everything above 6 m.
  await expect(panel.getByLabel('Cut above')).toBeChecked();
  await expect.poll(() => top('freezer')).toBe(6);
  expect(await planes()).toBe(1);
  await panel.getByLabel('Cut height').fill('2');
  await expect(panel.getByText('2.0 m up')).toBeVisible();
  await expect.poll(() => top('freezer')).toBe(2);
  // The freezer keeps its colour once its block is rebuilt.
  await expect.poll(() => color(page, 'freezer')).not.toBeNull();

  // Across, through the origin, taking away the north: the freezer reaches
  // 100 m north and is cut; the dispatch hall is wholly south and isn't.
  await panel.getByLabel('Cut above').click();
  await panel.getByLabel('Cut across').click();
  await expect.poll(() => top('freezer')).toBeCloseTo(18.5);
  expect(await planes()).toBe(1);
  // Taking away everything north of 90 m south leaves the freezer nothing.
  await panel.getByLabel('Cut position').fill('-90');
  await expect.poll(() => top('freezer')).toBeNull();
  expect(await top('dispatch')).not.toBeNull();
  // Flipped along the same line, the freezer comes back whole.
  await panel.getByRole('button', { name: 'Flip' }).click();
  await expect(panel.getByText('takes away S · 180°')).toBeVisible();
  await expect.poll(() => top('freezer')).toBeCloseTo(18.5);
  // South of 120 m south: the dispatch hall, at 80 m to 110 m south, is all kept.
  await panel.getByLabel('Cut position').fill('120');
  await expect.poll(() => top('dispatch')).toBeCloseTo(9.5);

  await panel.getByRole('button', { name: 'Done' }).click();
  await expect(panel).toHaveCount(0);
  await expect.poll(planes).toBe(0);
  expect(await top('freezer')).toBeCloseTo(18.5);
  expect(problems).toEqual([]);
});

test('a viewshed tints what a sensor sees, by its shape, and a line of sight finds what blocks it', async ({ page }) => {
  const problems = await open(page);
  type Vec3 = [number, number, number];
  type Hooks = {
    __scene: {
      sight: {
        clickLocal(point: Vec3): void;
        screenAt(point: Vec3): { x: number; y: number } | null;
        pixelAt(x: number, y: number): Promise<[number, number, number]>;
      };
    };
  };
  const pick = (point: Vec3) => page.evaluate((p) => (window as unknown as Hooks).__scene.sight.clickLocal(p), point);
  const colourAt = (point: Vec3) =>
    page.evaluate(async (p) => {
      const sight = (window as unknown as Hooks).__scene.sight;
      const at = sight.screenAt(p);
      return at ? sight.pixelAt(at.x, at.y) : null;
    }, point);
  // Each point's own colour, before any tint: the zones colour the ground.
  const untinted = new Map<Vec3, [number, number, number]>();
  const settle = async (point: Vec3) => {
    let last = '';
    await expect
      .poll(async () => {
        const now = JSON.stringify(await colourAt(point));
        const same = now === last;
        last = now;
        return same;
      }, { timeout: 30_000, intervals: [500] })
      .toBe(true);
    untinted.set(point, JSON.parse(last));
  };
  // Green where it's seen, red where it's hidden, as a shift from its own colour.
  const tint = async (point: Vec3) => {
    const [r, g] = (await colourAt(point))!;
    const [r0, g0] = untinted.get(point)!;
    const greener = g - g0 - (r - r0);
    return greener > 40 ? 'seen' : greener < -40 ? 'hidden' : 'none';
  };
  // Open ground south of the dispatch hall, which is 9 m tall and 110 m to
  // 80 m south; in front of it, and behind it.
  const sensor: Vec3 = [-60, -130, 0];
  const front: Vec3 = [-45, -170, 0];
  const behind: Vec3 = [-60, -62, 0];
  await settle(front);
  await settle(behind);

  await page.getByRole('button', { name: 'Sight' }).click();
  const panel = page.getByRole('region', { name: 'Sight' });
  await expect(panel.getByText('Click where the sensor goes')).toBeVisible();
  await panel.getByRole('button', { name: 'Dome' }).click();
  await pick(sensor);
  await expect(panel.getByText('all round')).toBeVisible();
  // A dome sees all round, but not through the hall: its 6 m eye is lower than the roof.
  // The first viewshed waits on its shaders.
  await expect.poll(() => tint(front), { timeout: 30_000 }).toBe('seen');
  await expect.poll(() => tint(behind)).toBe('hidden');

  // A cone looks one way: facing south, the ground behind the hall is out of it.
  await panel.getByRole('button', { name: 'Cone' }).click();
  await panel.getByLabel('Heading').fill('180');
  await expect(panel.getByText('facing S · 180°')).toBeVisible();
  await expect.poll(() => tint(behind)).toBe('none');
  await expect.poll(() => tint(front)).toBe('seen');
  // Aimed at the ground behind the hall, it faces north, and sees the hall in the way.
  await panel.getByRole('button', { name: 'Aim at…' }).click();
  await pick(behind);
  await expect(panel.getByText('facing N · 0°')).toBeVisible();
  await expect.poll(() => tint(behind)).toBe('hidden');
  // A rectangle, the same way.
  await panel.getByRole('button', { name: 'Rectangle' }).click();
  await expect.poll(() => tint(behind)).toBe('hidden');

  // From where the sensor stands, the hall's south wall is 20 m away, at eye height.
  await panel.getByRole('button', { name: 'Line of sight' }).click();
  await expect.poll(() => tint(behind)).toBe('none');
  await pick(sensor);
  await expect(panel.getByText('Click what it should see.')).toBeVisible();
  await pick(behind);
  const readout = panel.getByRole('definition');
  await expect(readout.nth(0)).toHaveText('No');
  // The wall is 20 m of the 68 m between them; the readout's metres are the
  // globe's, a little shorter north to south than the site's plan.
  const metres = async (n: number) => parseFloat(await readout.nth(n).innerText());
  expect(await metres(1)).toBeCloseTo(68, 0);
  expect((await metres(2)) / (await metres(1))).toBeCloseTo(20 / 68, 2);
  // A new line starts with the next click: across open ground, it's clear.
  await pick(sensor);
  await pick(front);
  await expect(readout.nth(0)).toHaveText('Yes');
  await expect(readout).toHaveCount(2);

  await panel.getByRole('button', { name: 'Done' }).click();
  await expect(panel).toHaveCount(0);
  expect(problems).toEqual([]);
});

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

import { chromium, type Page } from '@playwright/test';
import { resolve } from 'node:path';

/** Regenerates the README's screenshots. Needs `npm run dev` on port 5173. */
const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:5173';
const out = (name: string) => resolve(import.meta.dirname, '../docs/images', name);

const browser = await chromium.launch({
  channel: 'chrome',
  args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist'],
});

async function start(query = ''): Promise<Page> {
  const page = await browser.newPage({ viewport: { width: 1600, height: 950 } });
  await page.goto(`${BASE}/${query}`);
  await page.locator('canvas').first().waitFor();
  await page.waitForTimeout(11_000);
  return page;
}

const shot = (page: Page, name: string) => page.screenshot({ path: out(name) });

const overview = await start();
await overview.locator('.asset-row', { hasText: 'Freezer hall' }).click();
await overview.waitForTimeout(2500);
await overview.getByRole('button', { name: '← Site' }).click();
await overview.getByRole('button', { name: 'Whole site' }).click();
await overview.waitForTimeout(2500);
await shot(overview, 'overview.png');

const inspect = overview;
await inspect.locator('.asset-row', { hasText: 'Chiller 2' }).click();
await inspect.waitForTimeout(2500);
await shot(inspect, 'inspector.png');
await inspect.close();

const whatif = await start();
await whatif.getByRole('tab', { name: 'What-if' }).click();
await whatif.getByRole('radio', { name: /Refrigeration board trips/ }).check();
await whatif.getByRole('button', { name: 'Run forecast' }).click();
await whatif.waitForTimeout(1500);
await shot(whatif, 'what-if.png');

await whatif.getByRole('button', { name: 'Apply to the live twin' }).click();
await whatif.getByRole('radio', { name: 'Temp' }).click();
await whatif.getByRole('radio', { name: '600×' }).click();
await whatif.getByRole('tab', { name: 'Alerts' }).click();
await whatif.waitForTimeout(10_000);
await whatif.getByRole('radio', { name: '60×' }).click();
await shot(whatif, 'cascade.png');
await whatif.close();

const night = await start();
await night.getByRole('radio', { name: 'Power' }).click();
await night.getByRole('radio', { name: '600×' }).click();
await night.waitForTimeout(18_000);
await night.getByRole('button', { name: 'Pause' }).click();
await night.waitForTimeout(1500);
await shot(night, 'night-power.png');
await night.close();

const globe = await start();
await globe.getByRole('button', { name: 'Globe' }).click();
await globe.waitForTimeout(6000);
await shot(globe, 'globe.png');
await globe.close();

const small = await start('?twin=harbour-depot');
await shot(small, 'harbour-depot.png');
await small.close();

await browser.close();

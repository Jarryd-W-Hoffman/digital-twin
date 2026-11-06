import { resolve } from 'node:path';
import { chromium, type Page } from '@playwright/test';

/**
 * Regenerates the README's screenshots. Needs the app on BASE_URL with a freshly
 * seeded database, as `npm run test:e2e` serves it:
 *
 *   DB_DATABASE=$PWD/database/e2e.sqlite php artisan migrate:fresh --seed
 *   (cd public && DB_DATABASE=$PWD/../database/e2e.sqlite php -S 127.0.0.1:8199 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php)
 */
const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8199';
const out = (name: string) =>
    resolve(import.meta.dirname, '../docs/images', name);

const browser = await chromium.launch({
    channel: 'chrome',
    args: [
        '--use-angle=swiftshader',
        '--enable-unsafe-swiftshader',
        '--ignore-gpu-blocklist',
    ],
});
const context = await browser.newContext({
    viewport: { width: 1600, height: 950 },
    colorScheme: 'dark',
});

async function settle(page: Page, ms = 11_000) {
    await page.locator('canvas').first().waitFor({ timeout: 90_000 });
    await page.waitForTimeout(ms);
}

async function demo(query = ''): Promise<Page> {
    const page = await context.newPage();
    await page.goto(`${BASE}/demo${query}`);
    await settle(page);
    return page;
}

const shot = (page: Page, name: string) => page.screenshot({ path: out(name) });

// The viewer, on the public sample twins.
const overview = await demo();
await overview.getByRole('button', { name: 'Whole site' }).click();
await overview.waitForTimeout(3000);
await shot(overview, 'overview.png');

await overview.locator('.asset-row', { hasText: 'Chiller 2' }).click();
await overview.waitForTimeout(3000);
await shot(overview, 'inspector.png');

await overview.locator('.asset-row', { hasText: 'Fire water tank' }).click();
await overview.waitForTimeout(3000);
await shot(overview, 'custom-type.png');
await overview.close();

const whatif = await demo();
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

const night = await demo();
await night.getByRole('radio', { name: 'Power' }).click();
await night.getByRole('radio', { name: '600×' }).click();
await night.waitForTimeout(18_000);
await night.getByRole('button', { name: 'Pause' }).click();
await night.waitForTimeout(1500);
await shot(night, 'night-power.png');
await night.close();

const globe = await demo();
await globe.getByRole('button', { name: 'Globe' }).click();
await globe.waitForTimeout(6000);
await shot(globe, 'globe.png');
await globe.close();

// The control plane, signed in as the seeded owner.
const app = await context.newPage();
await app.goto(`${BASE}/login`);
await app.getByLabel('Email address').fill('test@example.com');
await app.getByLabel('Password', { exact: true }).fill('password');
await app.getByRole('button', { name: 'Log in' }).click();
await app.waitForURL(/\/twins$/);
await app.waitForTimeout(1000);
await shot(app, 'twins.png');

await app.goto(`${BASE}/organisation/members`);
await app.waitForTimeout(1000);
await shot(app, 'members.png');

await app.goto(`${BASE}/twins`);
await app.getByRole('link', { name: 'Harbour Depot' }).click();
await settle(app);
await app.getByRole('button', { name: 'Layout', exact: true }).click();
const editor = app.getByRole('complementary', { name: 'Layout' });
await editor
    .getByLabel('Upload file')
    .setInputFiles(
        resolve(import.meta.dirname, '../public/samples/models/pump-skid.glb'),
    );
await editor.getByLabel('Upload name').fill('Pump skid');
await editor.getByRole('button', { name: 'Upload', exact: true }).click();
await app.locator('.version-chip', { hasText: 'v2' }).waitFor({ timeout: 120_000 });
await settle(app, 8000);
await editor.getByLabel('East (m)').fill('-38');
await editor.getByLabel('North (m)').fill('-30');
await editor.getByLabel('Heading (°)').fill('30');
await editor.getByLabel('Scale').fill('2');
await app.waitForTimeout(3000);
await shot(app, 'layout-editor.png');

await editor.getByRole('button', { name: 'Save layout' }).click();
await app.locator('.version-chip', { hasText: 'v3' }).waitFor({ timeout: 120_000 });
await settle(app, 4000);
await app.getByRole('button', { name: 'Layout', exact: true }).click();
await app.waitForTimeout(3000);
await shot(app, 'harbour-depot.png');

await browser.close();

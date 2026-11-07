import { execFileSync } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { expect, test, type Page } from '@playwright/test';

/** Runs against the seeded e2e database (see playwright.config.ts). */

type Debug = {
  __twin: { getState(): { def?: { models?: { id: string; placement: { east: number; north: number } }[] }; canOperate: boolean } };
  __scene: { loadedLayers(): string[] };
};

const model = resolve('public/tilesets/context/context.glb');

function tilesetZip(): string {
  const zip = join(mkdtempSync(join(tmpdir(), 'meridian-')), 'context.zip');
  execFileSync('zip', ['-j', '-q', zip, 'public/tilesets/context/tileset.json', 'public/tilesets/context/context.glb']);
  return zip;
}

async function logIn(page: Page, email: string) {
  await page.goto('/login');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: 'Log in' }).click();
  await expect(page).toHaveURL(/\/projects$/);
  await page.getByRole('link', { name: /^Pilot sites/ }).click();
}

async function openTwin(page: Page, name: string) {
  await page.getByRole('link', { name }).click();
  await expect(page.locator('canvas').first()).toBeVisible({ timeout: 45_000 });
  await expect.poll(() => page.evaluate(() => Boolean((window as unknown as Debug).__twin.getState().def)), { timeout: 90_000 }).toBe(true);
}

const layers = (page: Page) => page.evaluate(() => (window as unknown as Debug).__scene?.loadedLayers() ?? []);

test('an owner uploads a model and a tileset, places the model, and the twin keeps it', async ({ page }) => {
  const problems: string[] = [];
  page.on('pageerror', (e) => problems.push(e.message));

  await logIn(page, 'test@example.com');
  await openTwin(page, 'Harbour Depot');
  await page.getByRole('button', { name: 'Layout' }).click();
  const editor = page.getByRole('complementary', { name: 'Layout' });

  await editor.getByLabel('Upload file').setInputFiles(model);
  await editor.getByLabel('Upload name').fill('Pump skid');
  await editor.getByRole('button', { name: 'Upload', exact: true }).click();
  await expect(editor.getByText('Pump skid', { exact: true })).toBeVisible();
  await expect(page.locator('.version-chip')).toHaveText('v2');
  await expect.poll(() => layers(page), { timeout: 45_000 }).toContainEqual(expect.stringMatching(/^upload-/));

  await editor.getByLabel('East (m)').fill('25');
  await editor.getByLabel('North (m)').fill('-10');
  await editor.getByRole('button', { name: 'Save layout' }).click();
  await expect(page.locator('.version-chip')).toHaveText('v3');

  await editor.getByLabel('Upload file').setInputFiles(tilesetZip());
  await editor.getByLabel('Upload name').fill('Neighbours');
  await editor.getByRole('button', { name: 'Upload', exact: true }).click();
  await expect(page.locator('.version-chip')).toHaveText('v4');
  await expect.poll(async () => (await layers(page)).length, { timeout: 45_000 }).toBe(2);

  await page.reload();
  await expect.poll(() => page.evaluate(() => (window as unknown as Debug).__twin.getState().def?.models?.[0]?.placement), { timeout: 45_000 })
    .toMatchObject({ east: 25, north: -10 });
  await expect(page.getByText('v3 Moved layers')).toHaveCount(0);
  expect(problems).toEqual([]);
});

test('a project library upload is shared: added to a twin, then taken out again without losing it', async ({ page }) => {
  page.on('dialog', (dialog) => void dialog.accept());
  await logIn(page, 'test@example.com');

  // Upload straight into the library, from the project page.
  await page.getByLabel('File (.glb, or a zipped 3D Tiles tileset)').setInputFiles(model);
  await page.getByLabel('Name', { exact: true }).fill('Shared crane');
  await page.getByRole('button', { name: 'Upload', exact: true }).click();
  await expect(page.getByText('Shared crane', { exact: true })).toBeVisible();

  await openTwin(page, 'Northshore Cold Chain Hub');
  const version = Number((await page.locator('.version-chip').innerText()).slice(1));
  await page.getByRole('button', { name: 'Layout' }).click();
  const editor = page.getByRole('complementary', { name: 'Layout' });
  const library = editor.getByRole('list', { name: 'Library' });
  await library.getByRole('listitem').filter({ hasText: 'Shared crane' }).getByRole('button', { name: 'Add' }).click();
  await expect(page.locator('.version-chip')).toHaveText(`v${version + 1}`);
  await expect(library.getByText('Shared crane')).toHaveCount(0);
  await expect.poll(() => layers(page), { timeout: 45_000 }).toContainEqual(expect.stringMatching(/^upload-/));

  await editor.getByRole('listitem').filter({ hasText: 'Shared crane' }).getByRole('button', { name: 'Remove' }).click();
  await expect(page.locator('.version-chip')).toHaveText(`v${version + 2}`);
  await expect(library.getByText('Shared crane')).toBeVisible();

  await page.getByRole('link', { name: 'Back to Pilot sites' }).click();
  await expect(page.getByText('Shared crane', { exact: true })).toBeVisible();
});

test('an owner starts an empty twin at a location from the project page', async ({ page }) => {
  const problems: string[] = [];
  page.on('pageerror', (e) => problems.push(e.message));
  await logIn(page, 'test@example.com');

  await page.getByRole('link', { name: 'New twin' }).click();
  await expect(page.getByLabel('Project')).toHaveValue(/.+/);
  await page.getByLabel('Name', { exact: true }).fill('Quay scan site');
  await expect(page.getByLabel('An empty site at a location')).toBeChecked();
  await page.getByLabel('Latitude').fill('-36.8442');
  await page.getByLabel('Longitude').fill('174.7676');
  await page.getByLabel('UTC offset (hours)').fill('13');
  await page.getByRole('button', { name: 'Create twin' }).click();

  await expect(page.locator('.twin-name')).toHaveText('Quay scan site');
  await expect(page.locator('canvas').first()).toBeVisible({ timeout: 45_000 });
  await expect.poll(() => page.evaluate(() => Boolean((window as unknown as Debug).__twin.getState().def)), { timeout: 90_000 }).toBe(true);
  await page.getByRole('link', { name: 'Back to Pilot sites' }).click();
  await expect(page.getByRole('link', { name: /^Quay scan site/ })).toBeVisible();
  expect(problems).toEqual([]);
});

test('a bad archive is refused with the reason', async ({ page }) => {
  await logIn(page, 'test@example.com');
  await openTwin(page, 'Northshore Cold Chain Hub');
  await page.getByRole('button', { name: 'Layout' }).click();
  const editor = page.getByRole('complementary', { name: 'Layout' });
  await editor.getByLabel('Upload file').setInputFiles({ name: 'evil.zip', mimeType: 'application/zip', buffer: Buffer.from('not a zip') });
  await editor.getByLabel('Upload name').fill('Evil');
  await editor.getByRole('button', { name: 'Upload', exact: true }).click();
  await expect(editor.getByRole('alert')).toContainText('not a readable zip file');
});

test('a viewer watches the twin but cannot edit or operate it', async ({ page }) => {
  await logIn(page, 'viewer@example.com');
  await openTwin(page, 'Northshore Cold Chain Hub');
  await expect(page.getByRole('button', { name: 'Layout' })).toHaveCount(0);
  expect(await page.evaluate(() => (window as unknown as Debug).__twin.getState().canOperate)).toBe(false);
  await page.getByRole('button', { name: /^Freezer hall/ }).first().click();
  await expect(page.getByRole('button', { name: 'Raise setpoint' })).toBeDisabled();
});

test('outsiders are sent to log in', async ({ page }) => {
  await page.goto('/twins');
  await expect(page).toHaveURL(/\/login$/);
});

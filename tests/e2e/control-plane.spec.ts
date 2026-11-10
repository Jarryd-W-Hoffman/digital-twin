import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
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

test('a placed model is moved by dragging its handles in the scene, and saved', async ({ page }) => {
  const problems: string[] = [];
  page.on('pageerror', (e) => problems.push(e.message));
  await logIn(page, 'test@example.com');
  await openTwin(page, 'Harbour Depot');
  await page.getByRole('button', { name: 'Layout' }).click();
  const editor = page.getByRole('complementary', { name: 'Layout' });
  await editor.getByLabel('Upload file').setInputFiles(model);
  await editor.getByLabel('Upload name').fill('Gizmo crate');
  await editor.getByRole('button', { name: 'Upload', exact: true }).click();
  const layer = editor.getByRole('listitem').filter({ hasText: 'Gizmo crate' }).filter({ has: page.getByLabel('East (m)') });
  await expect(layer).toBeVisible();
  const version = Number((await page.locator('.version-chip').innerText()).slice(1));
  await expect.poll(async () => (await layers(page)).length, { timeout: 45_000 }).toBeGreaterThan(0);

  type Gizmo = { __scene: { gizmo: { handleAt(h: string, bearing?: number): { x: number; y: number } | null } } };
  const handle = async (h: string, bearing?: number) =>
    (await page.evaluate(([name, b]) => (window as unknown as Gizmo).__scene.gizmo.handleAt(name as string, b as number | undefined), [h, bearing] as const))!;
  const box = (await page.locator('.globe canvas').first().boundingBox())!;
  /** Drags a handle (the ring from south-west) to a point on screen. */
  const drag = async (h: string, to: { x: number; y: number }) => {
    const from = await handle(h);
    await page.mouse.move(box.x + from.x, box.y + from.y);
    await page.mouse.down();
    for (let k = 1; k <= 8; k++)
      await page.mouse.move(box.x + from.x + ((to.x - from.x) * k) / 8, box.y + from.y + ((to.y - from.y) * k) / 8);
    await page.mouse.up();
  };
  const field = async (label: string) => Number(await layer.getByLabel(label).inputValue());
  const away = (a: { x: number; y: number }, b: { x: number; y: number }, by: number) => ({ x: a.x + (a.x - b.x) * by, y: a.y + (a.y - b.y) * by });

  await layer.getByRole('button', { name: 'Move in scene' }).click();
  await expect(layer.getByText('Drag the red, green or blue arrow')).toBeVisible();
  await expect.poll(() => page.evaluate(() => (window as unknown as Gizmo).__scene.gizmo.handleAt('slide'))).not.toBeNull();
  const start = { east: await field('East (m)'), north: await field('North (m)'), up: await field('Up (m)') };

  // Out along the east arrow: east grows, north and up stay.
  await drag('east', away(await handle('east'), await handle('slide'), 0.8));
  await expect.poll(() => field('East (m)')).toBeGreaterThan(start.east + 1);
  expect(await field('North (m)')).toBe(start.north);
  // Up the blue arrow.
  await drag('up', away(await handle('up'), await handle('slide'), 0.8));
  await expect.poll(() => field('Up (m)')).toBeGreaterThan(start.up + 1);
  // A quarter turn clockwise: the ring dragged from south-west round to north-west.
  const heading = await field('Heading (°)');
  await drag('turn', await handle('turn', 315));
  await expect.poll(async () => ((await field('Heading (°)')) - heading + 360) % 360).toBeGreaterThan(45);
  expect(((await field('Heading (°)')) - heading + 360) % 360).toBeLessThan(135);
  // Slid toward the north arrow's tip.
  const north = await field('North (m)');
  await drag('slide', await handle('north'));
  await expect.poll(() => field('North (m)')).toBeGreaterThan(north + 1);

  // Dragging handles selected nothing behind them.
  expect(await page.evaluate(() => (window as unknown as { __twin: { getState(): { selectedId: string | null } } }).__twin.getState().selectedId)).toBeNull();
  await layer.getByRole('button', { name: 'Done moving' }).click();
  await expect(layer.getByRole('button', { name: 'Move in scene' })).toBeVisible();
  const moved = await field('East (m)');
  await editor.getByRole('button', { name: 'Save layout' }).click();
  await expect(page.locator('.version-chip')).toHaveText(`v${version + 1}`);
  await expect.poll(() => page.evaluate(() => (window as unknown as Debug).__twin.getState().def?.models?.find((m) => m.id.startsWith('upload-') && m.placement.east !== 25)?.placement.east))
    .toBe(moved);
  expect(problems).toEqual([]);
});

test('a project library upload is shared: added to a twin, then taken out again without losing it', async ({ page }) => {
  page.on('dialog', (dialog) => void dialog.accept());
  await logIn(page, 'test@example.com');

  // Upload straight into the library, from the project page.
  await page.getByLabel(/^File \(/).setInputFiles(model);
  await page.getByLabel('Name', { exact: true }).fill('Shared crane');
  await page.getByRole('button', { name: 'Upload', exact: true }).click();
  await expect(page.getByText('Shared crane', { exact: true })).toBeVisible();

  await openTwin(page, 'Northshore Cold Chain Hub');
  const version = Number((await page.locator('.version-chip').innerText()).slice(1));
  await page.getByRole('button', { name: 'Layout' }).click();
  const editor = page.getByRole('complementary', { name: 'Layout' });
  const library = editor.getByRole('list', { name: 'Library', exact: true });
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

test('an owner adds a device, is shown its broker login once, and can reset or delete it', async ({ page }) => {
  await logIn(page, 'test@example.com');
  const devices = page.getByRole('region', { name: 'Devices' });
  const login = devices.getByRole('status', { name: 'Device login' });
  const list = devices.getByRole('list', { name: 'Devices' });
  await expect(devices.getByText(/below p\/pilot-sites-[a-z0-9]{4}\//)).toBeVisible();

  await devices.getByLabel('Device name').fill('Freezer probe 1');
  await devices.getByRole('button', { name: 'Add device' }).click();
  await expect(login.getByLabel('Device password')).toHaveValue(/^mdv_[A-Za-z0-9]{40}$/);
  const first = await login.getByLabel('Device password').inputValue();
  await expect(login.getByLabel('Example command')).toHaveValue(
    new RegExp(`^mosquitto_pub -h localhost -p 1883 -u device-[0-9a-f-]{36} -P ${first} -t 'p/pilot-sites-[a-z0-9]{4}/site/sensor/temp' -m 21.5$`),
  );
  await expect(list.getByText('Freezer probe 1')).toBeVisible();
  await expect(list.getByText(/never connected/)).toBeVisible();

  await page.reload();
  await expect(login).toHaveCount(0);

  page.once('dialog', (dialog) => void dialog.accept());
  await list.getByRole('button', { name: 'Reset the password of Freezer probe 1' }).click();
  await expect(login.getByLabel('Device password')).toHaveValue(/^mdv_/);
  expect(await login.getByLabel('Device password').inputValue()).not.toBe(first);

  await devices.getByLabel('Device name').fill('Freezer probe 1');
  await devices.getByRole('button', { name: 'Add device' }).click();
  await expect(devices.getByText('The name has already been taken.')).toBeVisible();

  page.once('dialog', (dialog) => void dialog.accept());
  await list.getByRole('button', { name: 'Delete Freezer probe 1' }).click();
  await expect(devices.getByText('No devices yet.')).toBeVisible();
  await expect(login).toHaveCount(0);
});

test('a point cloud waits for the converter, shows why it failed, and can be retried with an EPSG code', async ({ page }) => {
  await logIn(page, 'test@example.com');

  await page.getByLabel(/^File \(/).setInputFiles({ name: 'quay.las', mimeType: 'application/octet-stream', buffer: Buffer.from('LASF not really a scan') });
  await expect(page.getByLabel('EPSG code')).toBeVisible();
  await page.getByLabel('Thin to spacing (m)').fill('0.05');
  await page.getByRole('button', { name: 'Upload', exact: true }).click();
  const item = page.getByRole('listitem').filter({ hasText: 'quay.las' });
  await expect(item).toContainText('Converting 0%');

  // One go of the converter. The file is junk, so pdal info fails, as it
  // does when PDAL isn't installed at all.
  execFileSync('php', ['artisan', 'queue:work', 'conversions', '--queue=conversions', '--once', '--stop-when-empty'], {
    env: { ...process.env, DB_DATABASE: resolve('database/e2e.sqlite') },
  });
  await expect(item).toContainText('Failed', { timeout: 10_000 });
  await expect(item).toContainText('PDAL could not read the file');

  await item.getByLabel('EPSG code for quay').fill('2193');
  await item.getByRole('button', { name: 'Retry' }).click();
  await expect(item).toContainText('Converting 0%');

  await openTwin(page, 'Northshore Cold Chain Hub');
  await page.getByRole('button', { name: 'Layout' }).click();
  await expect(page.getByRole('list', { name: 'Library' }).getByText('quay')).toHaveCount(0);
});

test('a DEM converts to terrain on the queue, with only an EPSG option', async ({ page }) => {
  await logIn(page, 'test@example.com');

  await page.getByLabel(/^File \(/).setInputFiles({ name: 'ground.tif', mimeType: 'image/tiff', buffer: Buffer.from('II*\0 not really a DEM') });
  await expect(page.getByLabel('EPSG code')).toBeVisible();
  await expect(page.getByLabel('Thin to spacing (m)')).toHaveCount(0);
  await page.getByRole('button', { name: 'Upload', exact: true }).click();
  const item = page.getByRole('listitem').filter({ hasText: 'ground.tif' });
  await expect(item).toContainText('Converting 0%');

  // meridian-terrain only lives in the converter image, so here it fails.
  // Drain the queue: an earlier test may have left a retried scan on it.
  execFileSync('php', ['artisan', 'queue:work', 'conversions', '--queue=conversions', '--stop-when-empty'], {
    env: { ...process.env, DB_DATABASE: resolve('database/e2e.sqlite') },
  });
  await expect(item).toContainText('The DEM could not be tiled', { timeout: 10_000 });
  await expect(item.getByRole('button', { name: 'Retry' })).toBeVisible();
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

/** Stores readings for a twin's asset, as its gateway would have sent them. */
function recordReadings(twin: string, asset: string, values: number[]) {
  const code = `$t = App\\Models\\Twin::query()->where("name", "${twin}")->sole();
    resolve(App\\Actions\\RecordReadings::class)->handle($t, array_map(fn ($v, $i) => ["asset" => "${asset}", "metric" => "tempC", "value" => (float) $v, "at" => now()->subMinutes(30 * ($i + 1))->getTimestampMs()], [${values.join(',')}], array_keys([${values.join(',')}])));`;
  execFileSync('php', ['artisan', 'tinker', '--execute', code], { env: { ...process.env, DB_DATABASE: resolve('database/e2e.sqlite') } });
}

test('an owner maps an MQTT topic to a metric, and sees what sensors recorded', async ({ page }) => {
  recordReadings('Northshore Cold Chain Hub', 'freezer', [-21, -20.5, -19]);
  await logIn(page, 'test@example.com');
  await openTwin(page, 'Northshore Cold Chain Hub');

  await page.getByRole('button', { name: /Freezer hall/ }).first().click();
  const recorded = page.getByRole('region', { name: 'Recorded readings' });
  await expect(recorded.getByRole('img', { name: 'Temperature, recorded' })).toBeVisible();
  await recorded.getByLabel('Recorded metric').selectOption({ label: 'Setpoint' });
  await expect(recorded.getByText('No readings recorded in this range.', { exact: false })).toBeVisible();

  const version = Number((await page.locator('.version-chip').innerText()).slice(1));
  await page.getByRole('button', { name: 'Layout' }).click();
  const telemetry = page.getByRole('region', { name: 'Telemetry' });
  await expect(telemetry.getByText(/below this project's p\/pilot-sites-[a-z0-9]{4}\//)).toBeVisible();
  await telemetry.getByRole('button', { name: 'Add mapping' }).click();
  await telemetry.getByLabel('Topic').fill('northshore/#/temp');
  await telemetry.getByLabel('Asset').selectOption({ label: 'Freezer hall' });
  await telemetry.getByLabel('Metric').selectOption({ label: 'Temperature' });
  await telemetry.getByLabel('JSON path').fill('reading.c');
  await telemetry.getByRole('button', { name: 'Save mappings' }).click();
  await expect(telemetry.getByRole('alert')).toContainText('Telemetry northshore/#/temp: bad topic filter');

  await telemetry.getByLabel('Topic').fill('northshore/+/temp');
  await telemetry.getByRole('button', { name: 'Save mappings' }).click();
  await expect(page.locator('.version-chip')).toHaveText(`v${version + 1}`);

  await page.reload();
  await page.getByRole('button', { name: 'Layout' }).click();
  await expect(page.getByRole('region', { name: 'Telemetry' }).getByLabel('Topic')).toHaveValue('northshore/+/temp');
  await expect(page.getByRole('region', { name: 'Telemetry' }).getByLabel('JSON path')).toHaveValue('reading.c');
});

test('an owner registers an OPC UA server, then maps one of its nodes to a metric', async ({ page }) => {
  await logIn(page, 'test@example.com');
  const opcua = page.getByRole('region', { name: 'OPC UA servers' });
  const form = opcua.getByRole('form', { name: 'Add an OPC UA server' });
  await form.getByLabel('Server name').fill('Cold store PLC');
  await form.getByLabel('Endpoint').fill('http://plc.local:4840');
  await form.getByRole('button', { name: 'Add server' }).click();
  await expect(form.getByText('The endpoint must be an opc.tcp:// address')).toBeVisible();

  await form.getByLabel('Endpoint').fill('opc.tcp://plc.local:4840');
  await form.getByLabel('Security', { exact: true }).selectOption({ label: 'Sign and encrypt' });
  await expect(form.getByLabel('Security policy')).toHaveValue('Basic256Sha256');
  await form.getByLabel('Username (optional)').fill('meridian');
  await form.getByLabel('Password', { exact: true }).fill('plc-secret');
  await form.getByRole('button', { name: 'Add server' }).click();
  const list = opcua.getByRole('list', { name: 'OPC UA servers' });
  await expect(list.getByText('Cold store PLC')).toBeVisible();
  await expect(list.getByText('opc.tcp://plc.local:4840 · sign and encrypt, Basic256Sha256 · as meridian')).toBeVisible();

  await openTwin(page, 'Northshore Cold Chain Hub');
  const version = Number((await page.locator('.version-chip').innerText()).slice(1));
  await page.getByRole('button', { name: 'Layout' }).click();
  const mappings = page.getByRole('region', { name: 'OPC UA' });
  await mappings.getByRole('button', { name: 'Add OPC UA mapping' }).click();
  await expect(mappings.getByLabel('Server')).toHaveValue('Cold store PLC');
  await mappings.getByLabel('Node').fill('Store.TempC');
  await mappings.getByLabel('Asset').selectOption({ label: 'Freezer hall' });
  await mappings.getByLabel('Metric').selectOption({ label: 'Temperature' });
  await mappings.getByRole('button', { name: 'Save OPC UA mappings' }).click();
  await expect(mappings.getByRole('alert')).toContainText('OPC UA Store.TempC: node must be a node id');

  await mappings.getByLabel('Node').fill('ns=2;s=Store.TempC');
  await mappings.getByLabel('Sampling, ms').fill('500');
  await mappings.getByRole('button', { name: 'Save OPC UA mappings' }).click();
  await expect(page.locator('.version-chip')).toHaveText(`v${version + 1}`);

  await page.reload();
  await page.getByRole('button', { name: 'Layout' }).click();
  await expect(page.getByRole('region', { name: 'OPC UA' }).getByLabel('Node')).toHaveValue('ns=2;s=Store.TempC');
  await expect(page.getByRole('region', { name: 'OPC UA' }).getByLabel('Sampling, ms')).toHaveValue('500');
});

test('an owner maps a setpoint for writing, then lets operators write to the PLC', async ({ page }) => {
  page.on('dialog', (dialog) => void dialog.accept());
  await logIn(page, 'test@example.com');
  const opcua = page.getByRole('region', { name: 'OPC UA servers' });
  const form = opcua.getByRole('form', { name: 'Add an OPC UA server' });
  await form.getByLabel('Server name').fill('Pump PLC');
  await form.getByLabel('Endpoint').fill('opc.tcp://pumps.local:4840');
  await form.getByRole('button', { name: 'Add server' }).click();
  await expect(opcua.getByRole('list', { name: 'OPC UA servers' }).getByText('Pump PLC')).toBeVisible();

  await openTwin(page, 'Northshore Cold Chain Hub');
  const version = Number((await page.locator('.version-chip').innerText()).slice(1));
  await page.getByRole('button', { name: 'Layout' }).click();
  const section = page.getByRole('region', { name: 'OPC UA' });
  await section.getByRole('button', { name: 'Add OPC UA mapping' }).click();
  const row = section.getByRole('list', { name: 'OPC UA mappings' }).getByRole('listitem').last();
  await row.getByLabel('Server').selectOption('Pump PLC');
  await row.getByLabel('Node').fill('ns=2;s=Jockey.Start');
  // Only a setpoint can be written.
  await row.getByLabel('Asset').selectOption({ label: 'Freezer hall' });
  await expect(row.getByLabel('Write setpoints')).toBeDisabled();
  await row.getByLabel('Asset').selectOption({ label: 'Jockey pump' });
  await row.getByLabel('Metric').selectOption({ label: 'Start below' });
  await row.getByLabel('Write setpoints').check();
  await row.getByLabel('Scale').fill('100');
  await section.getByRole('button', { name: 'Save OPC UA mappings' }).click();
  await expect(page.locator('.version-chip')).toHaveText(`v${version + 1}`);

  const allowed = section.getByLabel("Write operators' setpoints to OPC UA");
  await expect(allowed).not.toBeChecked();
  await expect(section.getByText('Off: setpoints only change the model.')).toBeVisible();
  await allowed.click();
  await expect(page.getByText('Operators’ setpoints now go to the OPC UA servers.')).toBeVisible();
  await expect(allowed).toBeChecked();

  await page.reload();
  await page.getByRole('button', { name: 'Layout' }).click();
  const saved = page.getByRole('region', { name: 'OPC UA' }).getByRole('list', { name: 'OPC UA mappings' }).getByRole('listitem').last();
  await expect(saved.getByLabel('Write setpoints')).toBeChecked();
  await page.getByRole('region', { name: 'OPC UA' }).getByLabel("Write operators' setpoints to OPC UA").click();
  await expect(page.getByText('Writing to OPC UA is off.')).toBeVisible();
});

test('the type library: imported, compared with a twin, added to it, and refused where it cannot run', async ({ page }) => {
  page.on('dialog', (dialog) => void dialog.accept());
  await logIn(page, 'test@example.com');

  // The seeded library holds Northshore's types; import one more.
  await page.getByRole('link', { name: 'Types' }).click();
  const library = page.getByRole('list', { name: 'Types' });
  await expect(library.getByText('Jockey pump')).toBeVisible();
  await expect(library.getByText(/Reads fire-tank/)).toBeVisible();
  const file = join(mkdtempSync(join(tmpdir(), 'meridian-')), 'meter.json');
  writeFileSync(file, JSON.stringify({
    id: 'flow-meter', name: 'Flow meter', primary: 'flowLps',
    metrics: [{ key: 'flowLps', label: 'Flow', unit: 'L/s', default: 4 }],
    visual: { color: '#2f9e44', shape: 'box', height: 1.5 },
  }));
  await page.getByLabel('File').setInputFiles(file);
  await page.getByRole('button', { name: 'Import' }).click();
  await expect(page.getByText('1 type saved to the library.')).toBeVisible();
  await expect(library.getByText('Flow meter')).toBeVisible();

  // Northshore already has the library's two; the meter can be added.
  await page.goto('/projects');
  await page.getByRole('link', { name: /^Pilot sites/ }).click();
  await openTwin(page, 'Northshore Cold Chain Hub');
  await page.getByRole('button', { name: 'Layout' }).click();
  const editor = page.getByRole('complementary', { name: 'Layout' });
  const twinTypes = editor.getByRole('list', { name: 'Twin types' });
  await expect(twinTypes.getByText('in the library')).toHaveCount(2);
  await editor.getByRole('list', { name: 'Type library' }).getByRole('button', { name: 'Add' }).click();
  await expect(twinTypes.getByText('Flow meter')).toBeVisible();
  await expect(page.getByText('Flow meter added from the library.')).toBeVisible();
  await expect.poll(() => page.evaluate(() => (window as unknown as { __twin: { getState(): { def?: { types?: { id: string }[] } } } }).__twin.getState().def?.types?.map((t) => t.id)))
    .toEqual(['water-tank', 'jockey-pump', 'flow-meter']);

  // Harbour Depot has no fire tank, so the jockey pump can't run there.
  await page.goto('/projects');
  await page.getByRole('link', { name: /^Pilot sites/ }).click();
  await openTwin(page, 'Harbour Depot');
  await page.getByRole('button', { name: 'Layout' }).click();
  const depot = page.getByRole('complementary', { name: 'Layout' });
  await depot.getByRole('list', { name: 'Type library' }).getByRole('listitem').filter({ hasText: 'Jockey pump' }).getByRole('button', { name: 'Add' }).click();
  await expect(depot.getByRole('alert')).toContainText('refers to unknown asset fire-tank');
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

test('zones and assets are edited as forms, checked as they change, and saved as one version', async ({ page }) => {
  await logIn(page, 'test@example.com');
  await openTwin(page, 'Northshore Cold Chain Hub');
  const version = Number((await page.locator('.version-chip').innerText()).slice(1));

  await page.getByRole('button', { name: 'Definition' }).click();
  const editor = page.getByRole('complementary', { name: 'Definition' });
  const save = editor.getByRole('button', { name: 'Save', exact: true });
  await expect(editor.getByText('No changes yet.')).toBeVisible();
  await expect(save).toBeDisabled();

  await editor.getByRole('list', { name: 'Zones' }).getByRole('button', { name: /^Cold storage/ }).click();
  const zone = editor.getByRole('form', { name: 'Edit cold' });
  await zone.getByLabel('Id').fill('');
  await expect(editor.getByRole('alert')).toContainText('A zone has no id');
  await expect(editor.getByRole('alert')).toContainText('unknown zone cold');
  await expect(save).toBeDisabled();
  await editor.getByRole('form', { name: 'Edit zone' }).getByLabel('Id').fill('cold');
  await zone.getByLabel('Name').fill('Cold rooms');
  await zone.getByLabel('Footprint').fill('0, 0\n1, 1');
  await expect(editor.getByRole('alert')).toContainText('Zone cold: footprint needs 3+ points');
  await zone.getByLabel('Footprint').fill('-150, -60\n60, -60\n60, 110\n-150, 110');

  await editor.getByRole('tab', { name: 'Assets' }).click();
  await editor.getByRole('button', { name: 'Add asset' }).click();
  const asset = editor.getByRole('form', { name: 'Edit building' });
  await asset.getByLabel('Name').fill('Workshop');
  await asset.getByLabel('Zone').selectOption('plant');
  await asset.getByLabel('Kind').selectOption('vehicle');
  await expect(editor.getByRole('alert')).toContainText('building: unknown route undefined');
  await asset.getByLabel('Kind').selectOption('building');
  await asset.getByLabel('New parameter').fill('baseKw');
  await asset.getByRole('button', { name: 'Add', exact: true }).click();
  await asset.getByLabel('baseKw', { exact: true }).fill('12');
  await expect(editor.getByText('No problems.')).toBeVisible();

  await save.click();
  await expect(page.locator('.version-chip')).toHaveText(`v${version + 1}`);
  await expect(editor).toHaveCount(0);

  await page.reload();
  await expect.poll(() => page.evaluate(() => {
    const def = (window as unknown as { __twin: { getState(): { def?: { zones: { id: string; name: string }[]; assets: { id: string; name: string; zone?: string; params?: Record<string, number> }[] } } } }).__twin.getState().def;
    return def && { zone: def.zones[0].name, asset: def.assets.find((a) => a.id === 'building') };
  }), { timeout: 90_000 }).toEqual({ zone: 'Cold rooms', asset: expect.objectContaining({ name: 'Workshop', zone: 'plant', params: { baseKw: 12 } }) });

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Definition' }).click();
  await editor.getByRole('button', { name: 'Add zone' }).click();
  await editor.getByRole('button', { name: 'Discard' }).click();
  await expect(editor).toHaveCount(0);
  await expect(page.locator('.version-chip')).toHaveText(`v${version + 1}`);
});

test('types, rules and scenarios are edited with their nested lists', async ({ page }) => {
  await logIn(page, 'test@example.com');
  await openTwin(page, 'Harbour Depot');
  const version = Number((await page.locator('.version-chip').innerText()).slice(1));
  await page.getByRole('button', { name: 'Definition' }).click();
  const editor = page.getByRole('complementary', { name: 'Definition' });
  const alert = editor.getByRole('alert');

  await editor.getByRole('tab', { name: 'Types' }).click();
  await editor.getByRole('button', { name: 'Add type' }).click();
  const type = editor.getByRole('form', { name: 'Edit equipment' });
  await type.getByLabel('Name', { exact: true }).fill('Pump');
  await type.getByRole('button', { name: 'Add to metrics' }).click();
  const metric = type.getByRole('group', { name: 'Metrics 2' });
  await metric.getByLabel('Key').fill('flowLps');
  await metric.getByLabel('Unit').fill('L/s');
  await metric.getByLabel('Expression').fill('value * ');
  await expect(alert).toContainText('Type equipment: metric flowLps');
  await metric.getByLabel('Expression').fill('value * 2');
  await type.getByLabel('Primary metric').selectOption('flowLps');
  await expect(editor.getByText('No problems.')).toBeVisible();

  await editor.getByRole('tab', { name: 'Rules' }).click();
  await editor.getByRole('button', { name: 'Add rule' }).click();
  const rule = editor.getByRole('form', { name: 'Edit rule' });
  await rule.getByLabel('Name', { exact: true }).fill('Store warm');
  await rule.getByLabel('Or one asset').selectOption('store');
  await rule.getByLabel('Metric').selectOption('tempC');
  await rule.getByLabel('Threshold').fill('-15');
  await rule.getByLabel('Above or below').selectOption('');
  await expect(alert).toContainText('Rule rule: op must be gt or lt');
  await rule.getByLabel('Above or below').selectOption('gt');

  await editor.getByRole('tab', { name: 'Scenarios' }).click();
  await editor.getByRole('button', { name: 'Add scenario' }).click();
  const scenario = editor.getByRole('form', { name: 'Edit scenario' });
  await scenario.getByLabel('Name', { exact: true }).fill('Store trips');
  await scenario.getByRole('button', { name: 'Add to faults' }).click();
  await scenario.getByRole('group', { name: 'Faults 1' }).getByLabel('Target').selectOption('store');
  await expect(editor.getByText('No problems.')).toBeVisible();

  await editor.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.version-chip')).toHaveText(`v${version + 1}`);
  await expect.poll(() => page.evaluate(() => {
    const def = (window as unknown as { __twin: { getState(): { def?: { types?: { id: string; primary: string }[]; rules: { id: string; op?: string }[]; scenarios: { id: string; faults: { target?: string }[] }[] } } } }).__twin.getState().def;
    return def && [def.types?.find((t) => t.id === 'equipment')?.primary, def.rules.find((r) => r.id === 'rule')?.op, def.scenarios.find((s) => s.id === 'scenario')?.faults];
  }), { timeout: 90_000 }).toEqual(['flowLps', 'gt', [{ type: 'trip', target: 'store' }]]);
});

test('a zone is drawn on the ground, a corner dragged, an asset placed, and saved', async ({ page }) => {
  await logIn(page, 'test@example.com');
  await openTwin(page, 'Northshore Cold Chain Hub');
  const version = Number((await page.locator('.version-chip').innerText()).slice(1));
  type Scene = { __scene: { screenAt(p: [number, number]): { x: number; y: number } | null } };
  const screen = async (p: [number, number]) => {
    const at = await page.evaluate((point) => (window as unknown as Scene).__scene.screenAt(point), p);
    if (!at) throw new Error(`${p.join(', ')} is off screen`);
    const box = (await page.locator('canvas').first().boundingBox())!;
    return { x: box.x + at.x, y: box.y + at.y };
  };

  await page.getByRole('button', { name: 'Definition' }).click();
  const editor = page.getByRole('complementary', { name: 'Definition' });
  await editor.getByRole('button', { name: 'Add zone' }).click();
  const zone = editor.getByRole('form', { name: 'Edit zone' });
  await zone.getByRole('button', { name: 'Draw in scene' }).click();
  await zone.getByRole('button', { name: 'Start again' }).click();
  await expect(zone.getByLabel('Footprint')).toHaveValue('');

  for (const p of [[-60, 0], [-20, 0], [-20, 30], [-60, 30]] as [number, number][]) {
    const at = await screen(p);
    await page.mouse.click(at.x, at.y);
  }
  await expect(zone.getByLabel('Footprint')).toHaveValue(/^(.+\n){3}.+$/);
  await zone.getByRole('button', { name: 'Undo point' }).click();
  await expect(zone.getByLabel('Footprint')).toHaveValue(/^(.+\n){2}.+$/);

  const corner = await screen([-20, 30]);
  const target = await screen([-10, 40]);
  await page.mouse.move(corner.x, corner.y);
  await page.mouse.down();
  await page.mouse.move((corner.x + target.x) / 2, (corner.y + target.y) / 2, { steps: 4 });
  await page.mouse.move(target.x, target.y, { steps: 4 });
  await page.mouse.up();
  await zone.getByRole('button', { name: 'Done drawing' }).click();
  await expect(zone.getByRole('button', { name: 'Draw in scene' })).toBeVisible();

  const points = (await zone.getByLabel('Footprint').inputValue()).split('\n').map((l) => l.split(', ').map(Number));
  const expected = [[-60, 0], [-20, 0], [-10, 40]];
  expect(points).toHaveLength(3);
  points.forEach(([e, n], i) => {
    expect(Math.abs(e - expected[i][0])).toBeLessThan(1.5);
    expect(Math.abs(n - expected[i][1])).toBeLessThan(1.5);
  });

  await editor.getByRole('tab', { name: 'Assets' }).click();
  await editor.getByRole('button', { name: 'Add asset' }).click();
  const asset = editor.getByRole('form', { name: 'Edit building' });
  await asset.getByRole('button', { name: 'Place in scene' }).click();
  for (const p of [[-50, 10], [-40, 15]] as [number, number][]) {
    const at = await screen(p);
    await page.mouse.click(at.x, at.y);
  }
  await asset.getByRole('button', { name: 'Done placing' }).click();
  expect(Math.abs(Number(await asset.getByLabel('East (m)').inputValue()) + 40)).toBeLessThan(1.5);
  expect(Math.abs(Number(await asset.getByLabel('North (m)').inputValue()) - 15)).toBeLessThan(1.5);

  await editor.getByRole('button', { name: 'Save', exact: true }).click();
  // PHP's single-process server may still be serving the scene's tiles.
  await expect(page.locator('.version-chip')).toHaveText(`v${version + 1}`, { timeout: 30_000 });
  await expect.poll(() => page.evaluate(() => {
    const def = (window as unknown as { __twin: { getState(): { def?: { zones: { id: string; footprint: number[][] }[] } } } }).__twin.getState().def;
    return def?.zones.find((z) => z.id === 'zone')?.footprint.length;
  }), { timeout: 90_000 }).toBe(3);
});

test('an asset is bound to an element of an uploaded model: picked in the scene, coloured, and selected by clicking it', async ({ page }) => {
  const problems: string[] = [];
  page.on('pageerror', (e) => problems.push(e.message));
  type Scene = {
    __scene: { screenAt(p: [number, number]): { x: number; y: number } | null; featureColor(layer: string, id: string): string | null; appliedColor(id: string): string | null };
    __twin: { getState(): { selectedId: string | null; def?: { models?: { id: string }[]; assets: { id: string; feature?: { layer: string; id: string } }[] } } };
  };
  // scripts/generate-ifc.py's shed, converted by meridian-ifc: six elements, each a feature.
  // The camera looks in from the south, so its centre is behind the south wall.
  const southWall = '3SjGXv8sb2K9dxnB7Icxi7';
  const clickAt = async (p: [number, number]) => {
    // A new version rebuilds the scene, and its camera flies in: wait until the point stays put.
    let last = '';
    await expect.poll(async () => {
      const now = JSON.stringify(await page.evaluate((point) => (window as unknown as Scene).__scene.screenAt(point), p));
      const settled = now === last;
      last = now;
      return settled;
    }, { timeout: 30_000, intervals: [500] }).toBe(true);
    const at = await page.evaluate((point) => (window as unknown as Scene).__scene.screenAt(point), p);
    if (!at) throw new Error(`${p.join(', ')} is off screen`);
    const box = (await page.locator('canvas').first().boundingBox())!;
    await page.mouse.click(box.x + at.x, box.y + at.y);
  };

  await logIn(page, 'test@example.com');
  await page.getByRole('link', { name: 'New twin' }).click();
  await expect(page.getByLabel('Project')).toHaveValue(/.+/);
  await page.getByLabel('Name', { exact: true }).fill('Shed site');
  await page.getByLabel('Latitude').fill('-36.85');
  await page.getByLabel('Longitude').fill('174.76');
  await page.getByRole('button', { name: 'Create twin' }).click();
  await expect(page.locator('.twin-name')).toHaveText('Shed site');
  await expect(page.locator('canvas').first()).toBeVisible({ timeout: 45_000 });
  await expect.poll(() => page.evaluate(() => Boolean((window as unknown as Scene).__twin.getState().def)), { timeout: 90_000 }).toBe(true);

  await page.getByRole('button', { name: 'Layout' }).click();
  const layout = page.getByRole('complementary', { name: 'Layout' });
  await layout.getByLabel('Upload file').setInputFiles(resolve('tests/fixtures/shed.glb'));
  await layout.getByLabel('Upload name').fill('Depot shed');
  await layout.getByRole('button', { name: 'Upload', exact: true }).click();
  await expect(page.locator('.version-chip')).toHaveText('v2');
  await expect.poll(() => layers(page), { timeout: 45_000 }).toContainEqual(expect.stringMatching(/^upload-/));
  const layer = (await layers(page)).find((l) => l.startsWith('upload-'))!;
  await page.getByRole('button', { name: 'Close layout' }).click();

  await page.getByRole('button', { name: 'Definition' }).click();
  const editor = page.getByRole('complementary', { name: 'Definition' });
  await editor.getByRole('tab', { name: 'Assets' }).click();
  await editor.getByRole('list', { name: 'Assets' }).getByRole('button', { name: /^Main board/ }).click();
  const board = editor.getByRole('form', { name: 'Edit board' });
  const feature = board.getByRole('group', { name: 'Model feature' });
  await feature.getByRole('button', { name: 'Pick in scene' }).click();
  await expect(feature.getByText('Click an element of a model or tileset.')).toBeVisible();
  await clickAt([10, 6]);
  await expect(feature.getByRole('button', { name: 'Pick in scene' })).toBeVisible();
  await expect(feature.getByLabel('Layer')).toHaveValue(layer);
  await expect(feature.getByLabel('Feature')).toHaveValue(southWall);
  await expect(editor.getByText('No problems.')).toBeVisible();
  await editor.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.version-chip')).toHaveText('v3');

  // The board is drawn as the south wall now, in its status colour, not as its own box.
  await expect.poll(() => layers(page), { timeout: 45_000 }).toContain(layer);
  await expect.poll(() => page.evaluate(([l, id]) => (window as unknown as Scene).__scene.featureColor(l, id), [layer, southWall]), { timeout: 30_000 })
    .toMatch(/^#(?!ffffff)[0-9a-f]{6}$/);
  expect(await page.evaluate(() => (window as unknown as Scene).__scene.appliedColor('board'))).toBeNull();
  expect(await page.evaluate(([l]) => (window as unknown as Scene).__scene.featureColor(l, 'not-bound'), [layer])).toBe('#ffffff');

  await clickAt([10, 6]);
  await expect.poll(() => page.evaluate(() => (window as unknown as Scene).__twin.getState().selectedId)).toBe('board');
  expect(await page.evaluate(([l, id]) => (window as unknown as Scene).__scene.featureColor(l, id), [layer, southWall])).toBe('#ffd166');
  expect(problems).toEqual([]);
});

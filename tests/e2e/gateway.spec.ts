import { spawn, type ChildProcess } from 'node:child_process';
import { mkdirSync, openSync } from 'node:fs';
import { expect, test, type Browser, type Page } from '@playwright/test';

/**
 * A gateway started with a token from the Layout panel loads the twin from the
 * control plane, and the twin page follows it live. Runs on the seeded e2e app.
 */

type Debug = {
  __twin: {
    getState(): {
      sourceKind: string;
      status: string;
      canOperate: boolean;
      live?: { values: Record<string, number> };
    };
  };
};

const PORT = 8797;
const state = (page: Page) => page.evaluate(() => {
  const s = (window as unknown as Debug).__twin.getState();
  return { kind: s.sourceKind, status: s.status, canOperate: s.canOperate, freezer: s.live?.values['freezer.tempC'] };
});

async function logIn(browser: Browser, email: string): Promise<Page> {
  const page = await browser.newPage();
  await page.goto('/login');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: 'Log in' }).click();
  await expect(page).toHaveURL(/\/projects$/);
  await page.getByRole('link', { name: /^Pilot sites/ }).click();
  await page.getByRole('link', { name: 'Northshore Cold Chain Hub' }).click();
  await expect(page.locator('canvas').first()).toBeVisible({ timeout: 45_000 });
  return page;
}

test('a gateway runs the twin from the control plane, and the twin page follows it live', async ({ browser }) => {
  // Two browsers, a gateway and a PHP server on one machine.
  test.setTimeout(360_000);
  const owner = await logIn(browser, 'test@example.com');
  // Other specs share the seeded database and may have saved versions already.
  const version = Number((await owner.locator('.version-chip').innerText()).slice(1));
  await owner.getByRole('button', { name: 'Layout', exact: true }).click();
  const editor = owner.getByRole('complementary', { name: 'Layout' });
  await editor.getByRole('button', { name: 'Issue token' }).click();
  const command = await editor.getByLabel('Gateway command').inputValue();
  const token = /MERIDIAN_GATEWAY_TOKEN=(\S+)/.exec(command)?.[1];
  expect(token).toMatch(/^mgw_/);

  let gateway: ChildProcess | undefined;
  mkdirSync('test-results', { recursive: true });
  const log = openSync('test-results/gateway.log', 'w');
  try {
    // One node process (no npx or tsx wrapper), so kill() really stops it.
    gateway = spawn(process.execPath, ['--import', 'tsx', 'gateway/gateway.ts'], {
      env: {
        ...process.env,
        MERIDIAN_URL: 'http://127.0.0.1:8199',
        MERIDIAN_GATEWAY_TOKEN: token,
        GATEWAY_PORT: String(PORT),
        GATEWAY_PUBLIC_URL: `ws://127.0.0.1:${PORT}/ws`,
        GATEWAY_POLL_MS: '2000',
      },
      stdio: ['ignore', log, log],
    });
    // Health names the version it loaded, so a stray gateway can't pass for this one.
    await expect
      .poll(() => fetch(`http://127.0.0.1:${PORT}/health`).then((r) => (r.ok ? r.json() : null), () => null), { timeout: 60_000 })
      .toMatchObject({ status: 'ok', version });

    // The page polls for the gateway; reload rather than wait for it.
    await expect(async () => {
      await owner.reload();
      await expect(owner.getByRole('radio', { name: 'Live' })).toBeChecked({ timeout: 5000 });
    }).toPass({ timeout: 60_000 });
    await expect.poll(() => state(owner), { timeout: 60_000 }).toMatchObject({ kind: 'gateway', status: 'online', canOperate: true });

    // Readings sent to the gateway show up in the browser.
    const ingest = await fetch(`http://127.0.0.1:${PORT}/ingest`, {
      method: 'POST',
      headers: { authorization: `Bearer ${token}` },
      body: JSON.stringify({ asset: 'freezer', metric: 'tempC', value: -11.5, ttlS: 900 }),
    });
    expect(ingest.status).toBe(202);
    await expect.poll(async () => (await state(owner)).freezer, { timeout: 30_000 }).toBe(-11.5);

    // The gateway raises alerts itself and logs them in the control plane,
    // where acknowledging one records who did it.
    const reading = (value: number) =>
      fetch(`http://127.0.0.1:${PORT}/ingest`, {
        method: 'POST',
        headers: { authorization: `Bearer ${token}` },
        body: JSON.stringify({ asset: 'fire-tank', metric: 'levelPct', value, ttlS: 86_400 }),
      });
    expect((await reading(40)).status).toBe(202);
    const twinUrl = owner.url();
    const logged = owner.getByRole('list', { name: 'Alerts' }).getByRole('listitem').filter({ hasText: 'Fire water tank is below 60%' }).first();
    await expect(async () => {
      await owner.goto(`${twinUrl}/alerts`);
      await expect(logged).toBeVisible({ timeout: 2000 });
    }).toPass({ timeout: 60_000 });
    await expect(logged).toContainText('Open');
    await owner.goto(twinUrl);
    await expect.poll(() => state(owner), { timeout: 60_000 }).toMatchObject({ kind: 'gateway', status: 'online' });
    await owner.getByRole('tab', { name: 'Alerts' }).click();
    const inScene = owner.locator('.alert-item', { hasText: 'Fire water tank is below 60%' });
    await inScene.getByRole('button', { name: 'Acknowledge' }).click();
    await expect(inScene).toContainText('Acknowledged by Test User', { timeout: 30_000 });
    await owner.getByRole('link', { name: 'Alert log' }).click();
    await expect(logged).toContainText('Acknowledged by Test User');
    expect((await reading(90)).status).toBe(202);
    await expect(async () => {
      await owner.reload();
      await expect(logged).toContainText('cleared', { timeout: 2000 });
    }).toPass({ timeout: 60_000 });
    await owner.goto(twinUrl);
    await expect(owner.locator('canvas').first()).toBeVisible({ timeout: 45_000 });


    // A viewer follows the same gateway, and may only watch.
    const viewer = await logIn(browser, 'viewer@example.com');
    await expect(viewer.getByRole('radio', { name: 'Live' })).toBeChecked({ timeout: 30_000 });
    await expect.poll(() => state(viewer), { timeout: 60_000 }).toMatchObject({ kind: 'gateway', status: 'online', canOperate: false });
    await viewer.getByRole('radio', { name: 'Simulator' }).click();
    await expect.poll(async () => (await state(viewer)).kind, { timeout: 30_000 }).toBe('simulator');
    await viewer.close();
  } finally {
    gateway?.kill();
  }

  // Revoking cuts the gateway off and takes the switch away.
  owner.once('dialog', (dialog) => void dialog.accept());
  await owner.getByRole('button', { name: 'Layout', exact: true }).click();
  await editor.getByRole('button', { name: 'Revoke' }).click();
  await expect(owner.getByRole('radio', { name: 'Live' })).toHaveCount(0, { timeout: 30_000 });
  await expect(editor.getByRole('button', { name: 'Issue token' })).toBeVisible({ timeout: 30_000 });
});

test('an owner browses a PLC through the gateway and maps a node from it, or is told why not', async ({ browser }) => {
  test.setTimeout(360_000);
  const processes: ChildProcess[] = [];
  const log = openSync('test-results/gateway-browse.log', 'w');
  // The OPC UA simulator: Objects › Site › its variables.
  processes.push(
    spawn(process.execPath, ['--import', 'tsx', 'gateway/opcua-simulator.ts'], {
      env: { ...process.env, OPCUA_PORT: '4842' },
      stdio: ['ignore', log, log],
    }),
  );
  const owner = await browser.newPage();
  await owner.goto('/login');
  await owner.getByLabel('Email address').fill('test@example.com');
  await owner.getByLabel('Password', { exact: true }).fill('password');
  await owner.getByRole('button', { name: 'Log in' }).click();
  await owner.getByRole('link', { name: /^Pilot sites/ }).click();
  const servers = owner.getByRole('region', { name: 'OPC UA servers' });
  for (const [name, endpoint] of [
    ['Browse PLC', 'opc.tcp://127.0.0.1:4842/meridian'],
    ['Missing PLC', 'opc.tcp://127.0.0.1:4849/meridian'],
  ]) {
    await servers.getByLabel('Server name').fill(name);
    await servers.getByLabel('Endpoint').fill(endpoint);
    await servers.getByRole('button', { name: 'Add server' }).click();
    await expect(servers.getByRole('list', { name: 'OPC UA servers' }).getByText(name, { exact: true })).toBeVisible();
  }
  await owner.getByRole('link', { name: 'Northshore Cold Chain Hub' }).click();
  await expect(owner.locator('canvas').first()).toBeVisible({ timeout: 45_000 });
  const version = Number((await owner.locator('.version-chip').innerText()).slice(1));
  await owner.getByRole('button', { name: 'Layout', exact: true }).click();
  const editor = owner.getByRole('complementary', { name: 'Layout' });
  const browserGroup = editor.getByRole('group', { name: 'OPC UA browser' });
  await expect(browserGroup.getByText("The twin's gateway connects to the servers, so start it to browse them.")).toBeVisible();
  await expect(browserGroup.getByRole('button', { name: 'Browse' })).toBeDisabled();

  await editor.getByRole('button', { name: 'Issue token' }).click();
  const token = /MERIDIAN_GATEWAY_TOKEN=(\S+)/.exec(await editor.getByLabel('Gateway command').inputValue())?.[1];
  try {
    processes.push(
      spawn(process.execPath, ['--import', 'tsx', 'gateway/gateway.ts'], {
        env: {
          ...process.env,
          MERIDIAN_URL: 'http://127.0.0.1:8199',
          MERIDIAN_GATEWAY_TOKEN: token,
          GATEWAY_PORT: '8798',
          GATEWAY_PUBLIC_URL: 'ws://127.0.0.1:8798/ws',
          GATEWAY_POLL_MS: '2000',
          OPCUA_PKI_DIR: 'test-results/opcua-pki-browse',
        },
        stdio: ['ignore', log, log],
      }),
    );
    await expect
      .poll(() => fetch('http://127.0.0.1:8798/health').then((r) => (r.ok ? r.json() : null), () => null), { timeout: 60_000 })
      .toMatchObject({ status: 'ok', version });
    await expect(async () => {
      await owner.reload();
      await expect(owner.getByRole('radio', { name: 'Live' })).toBeChecked({ timeout: 5000 });
    }).toPass({ timeout: 60_000 });
    await owner.getByRole('button', { name: 'Layout', exact: true }).click();

    // A server that isn't there: browsing is the connection test.
    await browserGroup.getByLabel('Look in').selectOption('Missing PLC');
    await browserGroup.getByRole('button', { name: 'Browse' }).click();
    await expect(browserGroup.getByRole('alert')).toContainText("Couldn't browse Missing PLC", { timeout: 30_000 });

    await browserGroup.getByLabel('Look in').selectOption('Browse PLC');
    await browserGroup.getByRole('button', { name: 'Browse' }).click();
    await browserGroup.getByRole('button', { name: 'Site ›' }).click();
    const nodes = browserGroup.getByRole('list', { name: "What's on Browse PLC" });
    await expect(nodes.getByRole('listitem').filter({ hasText: 'Pump.StartBar' })).toContainText('Double = 6, writable');
    await expect(nodes.getByRole('listitem').filter({ hasText: 'Store.Status' })).not.toContainText('writable');
    await nodes.getByRole('button', { name: 'Map ns=1;s=Pump.StartBar' }).click();

    const row = editor.getByRole('region', { name: 'OPC UA' }).getByRole('list', { name: 'OPC UA mappings' }).getByRole('listitem').last();
    await expect(row.getByLabel('Server')).toHaveValue('Browse PLC');
    await expect(row.getByLabel('Node')).toHaveValue('ns=1;s=Pump.StartBar');
    await row.getByLabel('Asset').selectOption({ label: 'Jockey pump' });
    await row.getByLabel('Metric').selectOption({ label: 'Start below' });
    await row.getByLabel('Scale').fill('100');
    await editor.getByRole('button', { name: 'Save OPC UA mappings' }).click();
    await expect(owner.locator('.version-chip')).toHaveText(`v${version + 1}`);
  } finally {
    for (const p of processes) p.kill();
  }

  owner.once('dialog', (dialog) => void dialog.accept());
  await editor.getByRole('button', { name: 'Revoke' }).click();
  await expect(editor.getByRole('button', { name: 'Issue token' })).toBeVisible({ timeout: 30_000 });
});

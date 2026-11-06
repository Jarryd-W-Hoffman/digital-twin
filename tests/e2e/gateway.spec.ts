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
  await expect(page).toHaveURL(/\/twins$/);
  await page.getByRole('link', { name: 'Northshore Cold Chain Hub' }).click();
  await expect(page.locator('canvas').first()).toBeVisible({ timeout: 45_000 });
  return page;
}

test('a gateway runs the twin from the control plane, and the twin page follows it live', async ({ browser }) => {
  // Two browsers, a gateway and a PHP server on one machine.
  test.setTimeout(360_000);
  const owner = await logIn(browser, 'test@example.com');
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
      .toMatchObject({ status: 'ok', version: 1 });

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

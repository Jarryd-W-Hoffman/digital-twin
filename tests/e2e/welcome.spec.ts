import { expect, test } from '@playwright/test';

test('the landing page shows what a twin does, and leads into the samples', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveTitle(/Meridian/);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Your whole site, live on a real globe.');
  await expect(page.getByRole('link', { name: 'Get started' })).toBeVisible();

  // The showcase: a tab per thing a twin does, by click or by keyboard.
  const tabs = page.getByRole('tablist', { name: 'What a twin does' });
  const panel = page.getByRole('tabpanel');
  await expect(tabs.getByRole('tab', { selected: true })).toHaveText(/Run it live/);
  await tabs.getByRole('tab', { name: /Ask what if/ }).click();
  await expect(panel.getByRole('img')).toHaveAttribute('alt', /freezer hall would pass its limit/);
  await page.keyboard.press('ArrowDown');
  await expect(tabs.getByRole('tab', { selected: true })).toHaveText(/Measure the ground/);
  await expect(tabs.getByRole('tab', { selected: true })).toBeFocused();
  await page.keyboard.press('End');
  await expect(tabs.getByRole('tab', { selected: true })).toHaveText(/Pin issues/);
  // Choosing a tab stops it moving on by itself.
  await page.waitForTimeout(8000);
  await expect(tabs.getByRole('tab', { selected: true })).toHaveText(/Pin issues/);

  // The site over time: from the March survey to June.
  const slider = page.getByRole('slider', { name: 'March to June' });
  await expect(slider).toHaveValue('50');
  await page.getByRole('button', { name: /10 Mar 2026/ }).click();
  await expect(slider).toHaveValue('100');
  await expect(page.getByRole('button', { name: /10 Mar 2026/ })).toHaveAttribute('aria-pressed', 'true');
  await slider.focus();
  await page.keyboard.press('Home');
  await expect(slider).toHaveValue('0');
  await expect(page.getByRole('button', { name: /18 Jun 2026/ })).toHaveAttribute('aria-pressed', 'true');

  // A part of the definition lights its lines.
  const code = page.getByLabel('An excerpt of a twin definition');
  await page.getByRole('button', { name: /Where readings come from/ }).click();
  await expect(code).toHaveAttribute('data-focus', 'live');
  await expect(code.locator('.l-code-line[data-on="true"]')).toHaveText([/"telemetry"/]);

  // Every screenshot is there.
  for (const img of await page.locator('.landing img').all()) {
    await img.scrollIntoViewIfNeeded();
    await expect.poll(() => img.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth)).toBe(1600);
  }

  await page.getByRole('link', { name: 'Explore the sample twins' }).first().click();
  await expect(page).toHaveURL(/\/demo$/);
});

test('someone signed in is offered their dashboard', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Email address').fill('test@example.com');
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: 'Log in' }).click();
  await expect(page).toHaveURL(/\/projects$/);

  await page.goto('/');
  await expect(page.getByRole('link', { name: 'Get started' })).toHaveCount(0);
  await page.getByRole('link', { name: 'Open your dashboard' }).first().click();
  await expect(page).not.toHaveURL(/\/$/);
});

test('with less motion asked for, nothing moves by itself', async ({ browser }) => {
  const context = await browser.newContext({ reducedMotion: 'reduce' });
  const page = await context.newPage();
  await page.goto('/');
  const tabs = page.getByRole('tablist', { name: 'What a twin does' });
  await expect(tabs.getByRole('tab', { selected: true })).toHaveText(/Run it live/);
  await page.waitForTimeout(8000);
  await expect(tabs.getByRole('tab', { selected: true })).toHaveText(/Run it live/);
  await expect(page.locator('.l-globe animate')).toHaveCount(0);
  await context.close();
});

import { expect, test, type Page } from '@playwright/test';

/** Runs against the seeded e2e database (see playwright.config.ts). */

type Debug = {
    __twin: { getState(): { def?: { name: string }; canOperate: boolean } };
};

async function openDepot(page: Page) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('test@example.com');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/projects$/);
    await page.getByRole('link', { name: /^Pilot sites/ }).click();
    await page.getByRole('link', { name: 'Harbour Depot' }).click();
    await expect(page.locator('canvas').first()).toBeVisible({
        timeout: 45_000,
    });
}

test('an owner shares a twin by link; someone without an account sees it read only until it is revoked', async ({
    page,
    browser,
}) => {
    await openDepot(page);
    await page.getByRole('button', { name: 'Share' }).click();
    const panel = page.getByRole('complementary', { name: 'Share' });
    await panel.getByLabel("Who it's for").fill('Harbour board walkthrough');
    await panel.getByLabel('Expires in').selectOption('7');
    await panel.getByRole('button', { name: 'Make link' }).click();
    const url = await panel
        .getByLabel('Share link', { exact: true })
        .inputValue();
    expect(url).toMatch(/\/share\/[A-Za-z0-9]{40}$/);
    await expect(
        panel.getByRole('listitem', { name: 'Harbour board walkthrough' }),
    ).toContainText('Not opened yet');

    const guest = await (await browser.newContext()).newPage();
    const opened = await guest.goto(url);
    expect(opened?.headers()['referrer-policy']).toBe('no-referrer');
    await expect(guest.locator('.twin-name')).toHaveText('Harbour Depot');
    await expect(guest.getByText('Shared · read only')).toBeVisible();
    await expect(guest.locator('canvas').first()).toBeVisible({
        timeout: 45_000,
    });
    await expect
        .poll(
            () =>
                guest.evaluate(
                    () =>
                        (window as unknown as Debug).__twin.getState().def
                            ?.name ?? null,
                ),
            { timeout: 90_000 },
        )
        .toBe('Harbour Depot');
    expect(
        await guest.evaluate(
            () => (window as unknown as Debug).__twin.getState().canOperate,
        ),
    ).toBe(false);
    for (const name of ['Share', 'Layout', 'Settings', 'Issues']) {
        await expect(
            guest.getByRole('button', { name, exact: true }),
        ).toHaveCount(0);
    }

    await page.reload();
    await page.getByRole('button', { name: 'Share' }).click();
    await expect(
        panel.getByRole('listitem', { name: 'Harbour board walkthrough' }),
    ).toContainText('Last opened');
    page.once('dialog', (dialog) => void dialog.accept());
    await panel
        .getByRole('listitem', { name: 'Harbour board walkthrough' })
        .getByRole('button', { name: 'Revoke' })
        .click();
    await expect(panel.getByText('Not shared yet.')).toBeVisible();

    expect((await guest.goto(url))?.status()).toBe(404);
});

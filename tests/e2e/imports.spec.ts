import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { expect, test, type Page } from '@playwright/test';

/** Runs against the seeded e2e database (see playwright.config.ts). */

const env = { ...process.env, DB_DATABASE: resolve('database/e2e.sqlite') };

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

/** A day this many days ago, as YYYY-MM-DD. */
const daysAgo = (days: number) =>
    new Date(Date.now() - days * 86_400_000).toISOString().slice(0, 10);

test('an owner imports a CSV of readings, sees them in the history, and removes them', async ({
    page,
}) => {
    await openDepot(page);
    await page.getByRole('button', { name: 'Layout' }).click();
    const editor = page.getByRole('complementary', { name: 'Layout' });

    const csv = [
        'Date;Seafood store · Temperature;Door',
        `${daysAgo(2)} 06:00;1,5;0`,
        `${daysAgo(2)} 06:30;1,75;1`,
        `${daysAgo(2)} 07:00;warm;0`,
    ].join('\n');
    await editor.getByLabel('CSV file').setInputFiles({
        name: 'store-logger.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(csv),
    });

    const layout = editor.getByLabel('How the file is laid out');
    await expect(layout.getByLabel('Separated by')).toHaveValue(';');
    await expect(layout.getByLabel('Time column')).toHaveValue('Date');
    await expect(
        layout.getByText(`First time: ${daysAgo(2)} 06:00`),
    ).toBeVisible();
    // Matched by name; the door isn't a metric of the twin, so it's skipped.
    await expect(
        layout.getByLabel('Metric for Seafood store · Temperature'),
    ).toHaveValue('store|tempC');
    await expect(layout.getByLabel('Metric for Door')).toHaveValue('');
    await layout.getByRole('button', { name: 'Import 1 series' }).click();

    const item = editor
        .getByRole('list', { name: 'Imports' })
        .getByRole('listitem', { name: 'store-logger.csv' });
    await expect(item.getByRole('status')).toHaveText('Importing…');

    execFileSync('php', ['artisan', 'queue:work', '--stop-when-empty'], {
        env,
    });
    await expect(item.getByRole('status')).toContainText(
        '2 readings in 1 series',
        { timeout: 15_000 },
    );
    await expect(item.getByRole('status')).toContainText('1 skipped.');
    await item.getByText('Why some were skipped').click();
    await expect(item).toContainText(
        "Row 4: “warm” in Seafood store · Temperature isn't a number.",
    );

    // The history has them.
    await page.getByRole('button', { name: 'Close layout' }).click();
    await page
        .getByRole('button', { name: /^Seafood store/ })
        .first()
        .click();
    const recorded = page.getByRole('region', { name: 'Recorded readings' });
    await recorded.getByRole('button', { name: '7 days' }).click();
    await expect(
        recorded.getByRole('img', { name: 'Temperature, recorded' }),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Layout' }).click();
    page.once('dialog', (dialog) => void dialog.accept());
    await item.getByRole('button', { name: 'Remove' }).click();
    await expect(editor.getByRole('list', { name: 'Imports' })).toHaveCount(0);

    await page.goto('/organisation/audit');
    await expect(
        page.getByRole('table', { name: 'Audit log' }).getByRole('row', {
            name: /Removed imported readings: store-logger\.csv/,
        }),
    ).toBeVisible();
});

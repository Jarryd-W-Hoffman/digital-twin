import { expect, test, type Page } from '@playwright/test';

/** Runs against the seeded e2e database (see playwright.config.ts). */

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

test('an owner watches a metric for anomalies, leaves a shutdown out, changes how it is watched and stops', async ({
    page,
}) => {
    await openDepot(page);
    await page.getByRole('button', { name: 'Anomalies' }).click();
    const panel = page.getByRole('complementary', { name: 'Anomalies' });
    await expect(panel.getByText('No metrics watched yet.')).toBeVisible();

    await panel
        .getByLabel('Metric')
        .selectOption({ label: 'Seafood store · Temperature' });
    await panel.getByLabel('Alert when unchanged for (hours)').fill('6');
    await panel.getByLabel('Severity').selectOption('critical');
    await panel.getByRole('button', { name: 'Watch' }).click();

    const row = panel.getByRole('listitem', {
        name: 'Seafood store · Temperature',
    });
    await expect(row).toContainText('learning, 0 of 14 days');
    await expect(row).toContainText('unchanged for 6 h');
    await expect(row).toContainText('critical');

    await panel.getByLabel('From', { exact: true }).fill('2026-10-01T06:00');
    await panel.getByLabel('To', { exact: true }).fill('2026-10-03T18:00');
    await panel.getByLabel('Why', { exact: true }).fill('Chiller replaced');
    await panel.getByRole('button', { name: 'Leave out' }).click();
    const shutdown = panel.getByRole('listitem', { name: 'Chiller replaced' });
    await expect(shutdown).toBeVisible();

    await row.getByRole('button', { name: 'Change' }).click();
    await row.getByLabel('Alert when unusual for the time of day').uncheck();
    await row.getByLabel('Alert when unchanged for (hours)').fill('');
    await row.getByRole('button', { name: 'Save' }).click();
    await expect(row.getByRole('alert')).toHaveText(
        'Check the band, flatlines, or both.',
    );
    await row.getByLabel('Alert when unchanged for (hours)').fill('12');
    await row.getByRole('button', { name: 'Save' }).click();
    await expect(row).toContainText('unchanged for 12 h');
    await expect(row).not.toContainText('learning');

    await shutdown.getByRole('button', { name: 'Count it again' }).click();
    await expect(panel.getByText('Nothing left out.')).toBeVisible();
    page.once('dialog', (dialog) => void dialog.accept());
    await row.getByRole('button', { name: 'Stop watching' }).click();
    await expect(panel.getByText('No metrics watched yet.')).toBeVisible();

    await page.goto('/organisation/audit');
    const log = page.getByRole('table', { name: 'Audit log' });
    await expect(
        log.getByRole('row', {
            name: /Stopped watching a metric for anomalies: store\.tempC/,
        }),
    ).toBeVisible();
    await expect(
        log.getByRole('row', {
            name: /Left a period out of anomaly detection: Chiller replaced/,
        }),
    ).toBeVisible();
});

import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { expect, test, type Page } from '@playwright/test';

/** Runs against the seeded e2e database (see playwright.config.ts). */

const env = { ...process.env, DB_DATABASE: resolve('database/e2e.sqlite') };

/** Two surveys of the depot's ground in its project, as converted DEMs. */
function addSurveys() {
    const code = `$p = App\\Models\\Twin::query()->where("name", "Harbour Depot")->sole()->project;
        foreach (["March survey", "April survey"] as $name) {
            $a = App\\Models\\ProjectAsset::factory()->for($p)->survey($name)->create();
            Illuminate\\Support\\Facades\\Storage::disk(config("meridian.assets_disk"))->put($a->directory."/surface.tif", "II*\\0 heights");
        }`;
    execFileSync('php', ['artisan', 'tinker', '--execute', code], { env });
}

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

/** Clicks the ground east and north of the twin's origin, in metres. */
async function clickGround(page: Page, point: [number, number]) {
    const at = await page.evaluate(
        (p) =>
            (
                window as unknown as {
                    __scene: {
                        screenAt(p: [number, number]): {
                            x: number;
                            y: number;
                        } | null;
                    };
                }
            ).__scene.screenAt(p),
        point,
    );
    const box = (await page.locator('.globe canvas').first().boundingBox())!;
    await page.mouse.click(box.x + at!.x, box.y + at!.y);
}

test('an owner compares two surveys inside an area drawn in the scene, sees the volumes and where, and deletes it', async ({
    page,
}) => {
    addSurveys();
    await openDepot(page);
    await page.getByRole('button', { name: 'Whole site' }).click();
    await page.waitForTimeout(3000);
    await page.getByRole('button', { name: 'Volumes' }).click();
    const panel = page.getByRole('complementary', { name: 'Volumes' });
    await expect(panel.getByText('No comparisons yet.')).toBeVisible();

    await panel.getByRole('button', { name: 'New comparison' }).click();
    await panel.getByLabel('Name').fill('Yard fill');
    await panel.getByRole('button', { name: 'Compare' }).click();
    await expect(panel.getByRole('alert').first()).toContainText(
        'Draw the area with three points or more.',
    );

    await panel.getByRole('button', { name: 'Draw in scene' }).click();
    for (const point of [
        [-40, -40],
        [40, -40],
        [40, 40],
        [-40, 40],
    ] as [number, number][])
        await clickGround(page, point);
    await expect(panel.getByText('4 points.')).toBeVisible();
    await panel.getByRole('button', { name: 'Done drawing' }).click();
    // Ticked in the order surveyed.
    await panel.getByLabel('March survey').check();
    await panel.getByLabel('April survey').check();
    await expect(panel.getByText('1. March survey')).toBeVisible();
    await panel.getByRole('button', { name: 'Compare' }).click();

    const item = panel.getByRole('listitem', { name: 'Yard fill' });
    await expect(item.getByRole('status')).toHaveText('Measuring…');

    // One go of the converter, with a stand-in for meridian-volume.
    execFileSync(
        'php',
        [
            'artisan',
            'queue:work',
            'conversions',
            '--queue=conversions',
            '--stop-when-empty',
        ],
        {
            env: {
                ...env,
                MERIDIAN_VOLUME_TOOL: resolve(
                    'tests/e2e/fixtures/fake-meridian-volume',
                ),
            },
        },
    );
    const changes = item.getByRole('list', { name: 'Yard fill changes' });
    await expect(changes).toBeVisible({ timeout: 15_000 });
    const april = changes.getByRole('listitem', { name: 'April survey' });
    await expect(april).toContainText('120 m³');
    await expect(april).toContainText('450 m³');
    await expect(april).toContainText('+330 m³');
    await expect(april).not.toContainText('covered');

    await april
        .getByRole('button', { name: 'Show the change to April survey' })
        .click();
    await expect
        .poll(() =>
            page.evaluate(
                () =>
                    (
                        window as unknown as {
                            __scene: {
                                surveyOverlay(): { loaded: boolean } | null;
                            };
                        }
                    ).__scene.surveyOverlay()?.loaded,
            ),
        )
        .toBe(true);

    // Renaming keeps what was measured.
    await item.getByRole('button', { name: 'Edit' }).click();
    await panel.getByLabel('Name').fill('Yard fill, west');
    await panel.getByRole('button', { name: 'Save' }).click();
    const renamed = panel.getByRole('listitem', { name: 'Yard fill, west' });
    await expect(renamed.getByRole('list')).toContainText('+330 m³');

    page.once('dialog', (dialog) => void dialog.accept());
    await renamed.getByRole('button', { name: 'Delete' }).click();
    await expect(panel.getByText('No comparisons yet.')).toBeVisible();

    await page.goto('/organisation/audit');
    await expect(
        page.getByRole('table', { name: 'Audit log' }).getByRole('row', {
            name: /Deleted a survey comparison: Yard fill, west/,
        }),
    ).toBeVisible();
});

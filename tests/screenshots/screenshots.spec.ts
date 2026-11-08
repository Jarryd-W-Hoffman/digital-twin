import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { mkdirSync, openSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test, type Locator, type Page } from '@playwright/test';

/**
 * The README's and the manual's screenshots, taken on the seeded app by
 * walking through what the manual describes. Besides the app and gateway
 * Playwright starts, it uses Docker for two real services: the MQTT broker
 * (mosquitto-go-auth, asking this app) and the point cloud converter image
 * (`docker compose build converter`, tagged meridian-converter:dev). It also
 * runs the OPC UA simulator, which the gateway reads the store's door from.
 *
 *     npm run build && npm run screenshots
 */

const ROOT = resolve(import.meta.dirname, '../..');
const DB = resolve(ROOT, 'database/e2e.sqlite');
const BROKER = 'meridian-screenshots-mqtt';
const CONVERTER =
    process.env.MERIDIAN_CONVERTER_IMAGE ?? 'meridian-converter:dev';
const GATEWAY_PORT = 8796;
const OPCUA_PORT = 4841;
/** Where the IFC shed stands, and the twin made for it. */
const SHED = [-36.85, 174.76] as const;

const image = (name: string) => resolve(ROOT, 'docs/images', `${name}.png`);

async function shot(target: Page | Locator, name: string) {
    await target.screenshot({ path: image(name), animations: 'disabled' });
}

/** Waits for the globe to load and the flight down to finish. */
async function settle(page: Page, ms = 11_000) {
    await page.locator('canvas').first().waitFor({ timeout: 90_000 });
    await page.waitForTimeout(ms);
}

const run = (command: string, args: string[]) =>
    execFileSync(command, args, {
        cwd: ROOT,
        env: { ...process.env, DB_DATABASE: DB },
        encoding: 'utf8',
    });

function artisan(...args: string[]) {
    return run('php', ['artisan', ...args]);
}

/** A day of readings every 10 minutes, newest first, as a gateway would have sent them. */
function backfill(
    twin: string,
    asset: string,
    metric: string,
    values: number[],
) {
    artisan(
        'tinker',
        '--execute',
        `$t = App\\Models\\Twin::query()->where("name", "${twin}")->sole();
     $values = json_decode('${JSON.stringify(values)}');
     resolve(App\\Actions\\RecordReadings::class)->handle($t, array_map(fn ($v, $i) => ["asset" => "${asset}", "metric" => "${metric}", "value" => (float) $v, "at" => now()->subMinutes(10 * ($i + 1))->getTimestampMs()], $values, array_keys($values)));`,
    );
}

const processes: ChildProcess[] = [];

// One job at a time, run by the converter image against this app's database and files.
function convertOne(name: string) {
    const log = openSync(resolve(ROOT, `test-results/${name}.log`), 'w');
    processes.push(
        spawn(
            'docker',
            [
                'run',
                '--rm',
                '-v',
                `${ROOT}:/var/www/html`,
                '-e',
                'DB_CONNECTION=sqlite',
                '-e',
                'DB_DATABASE=/var/www/html/database/e2e.sqlite',
                CONVERTER,
                'php',
                'artisan',
                'queue:work',
                'conversions',
                '--queue=conversions',
                '--once',
                '--stop-when-empty',
                '--timeout=600',
            ],
            { cwd: ROOT, stdio: ['ignore', log, log] },
        ),
    );
}

test.beforeAll(() => {
    mkdirSync(resolve(ROOT, 'test-results'), { recursive: true });
    execFileSync('docker', ['rm', '-f', BROKER], { stdio: 'ignore' });
    // On the host network, so it can reach the app on 127.0.0.1:8199.
    run('docker', [
        'run',
        '-d',
        '--rm',
        '--name',
        BROKER,
        '--network',
        'host',
        '-e',
        'MERIDIAN_AUTH_HOST=127.0.0.1',
        '-e',
        'MERIDIAN_AUTH_PORT=8199',
        '-e',
        'MERIDIAN_MQTT_AUTH_SECRET=screenshots',
        '-v',
        `${resolve(ROOT, 'docker/mosquitto')}:/meridian:ro`,
        '--entrypoint',
        '/usr/bin/tini',
        'iegomez/mosquitto-go-auth:3.0.0-mosquitto_2.0.18',
        '--',
        '/bin/sh',
        '/meridian/start.sh',
    ]);
    run('docker', [
        'run',
        '--rm',
        '-v',
        `${ROOT}:/var/www/html`,
        CONVERTER,
        'python3',
        'scripts/generate-point-cloud.py',
        'test-results/wharf.las',
    ]);
    run('docker', [
        'run',
        '--rm',
        '-v',
        `${ROOT}:/var/www/html`,
        CONVERTER,
        'python3',
        'scripts/generate-terrain.py',
        'test-results/depot-ground.tif',
    ]);
    run('docker', [
        'run',
        '--rm',
        '-v',
        `${ROOT}:/var/www/html`,
        CONVERTER,
        'python3',
        'scripts/generate-ifc.py',
        'test-results/shed.ifc',
        '--site',
        String(SHED[0]),
        String(SHED[1]),
    ]);
});

test.beforeAll(() => {
    const log = openSync(
        resolve(ROOT, 'test-results/screenshots-opcua.log'),
        'w',
    );
    processes.push(
        spawn(
            process.execPath,
            ['--import', 'tsx', 'gateway/opcua-simulator.ts'],
            {
                cwd: ROOT,
                env: { ...process.env, OPCUA_PORT: String(OPCUA_PORT) },
                stdio: ['ignore', log, log],
            },
        ),
    );
});

test.afterAll(() => {
    for (const p of processes) p.kill();
    execFileSync('docker', ['rm', '-f', BROKER], { stdio: 'ignore' });
});

test('screenshots', async ({ page, context }) => {
    await test.step('signing in and projects', async () => {
        await page.goto('/login');
        await shot(page, 'login');
        await page.getByLabel('Email address').fill('test@example.com');
        await page.getByLabel('Password', { exact: true }).fill('password');
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page).toHaveURL(/\/projects$/);
        await expect(
            page.getByRole('link', { name: /^Pilot sites/ }),
        ).toBeVisible();
        await shot(page, 'projects');
    });

    const library = page.getByRole('region', { name: 'Library' });
    await test.step('the library', async () => {
        await page.getByRole('link', { name: /^Pilot sites/ }).click();
        await expect(
            page.getByRole('heading', { name: 'Pilot sites' }),
        ).toBeVisible();

        const upload = async (file: string, name: string) => {
            await page
                .getByLabel(/^File \(/)
                .setInputFiles(resolve(ROOT, file));
            await page.getByLabel('Name', { exact: true }).fill(name);
            await page
                .getByRole('button', { name: 'Upload', exact: true })
                .click();
            await expect(
                library.getByRole('listitem').filter({ hasText: name }),
            ).toBeVisible({ timeout: 60_000 });
        };
        run('sh', [
            '-c',
            'cd public/tilesets/context && rm -f ../../../test-results/context.zip && zip -q ../../../test-results/context.zip tileset.json context.glb',
        ]);
        await upload('public/samples/models/pump-skid.glb', 'Pump skid');
        await upload('test-results/context.zip', 'Depot surroundings');
        await upload('test-results/wharf.las', 'Wharf scan');
        const scan = library
            .getByRole('listitem')
            .filter({ hasText: 'Wharf scan' });
        await expect(scan).toContainText('Converting 0%');

        convertOne('converter');
        await expect(scan).toContainText(/Converting (4[5-9]|[5-8]\d)%/, {
            timeout: 300_000,
        });
        await scan.getByText('Log').click();
        await shot(library, 'library-converting');
        await expect(scan).toContainText('Point cloud', { timeout: 600_000 });
        await shot(library, 'library');

        await upload('test-results/depot-ground.tif', 'Depot ground survey');
        const ground = library
            .getByRole('listitem')
            .filter({ hasText: 'Depot ground survey' });
        await expect(ground).toContainText('Converting');
        convertOne('terrain');
        await expect(ground).toContainText('Terrain', { timeout: 300_000 });
        await shot(ground, 'library-terrain');
    });

    const devices = page.getByRole('region', { name: 'Devices' });
    let device = { username: '', password: '' };
    await test.step('devices', async () => {
        await devices.getByLabel('Device name').fill('Seafood store probe');
        await devices.getByRole('button', { name: 'Add device' }).click();
        const login = devices.getByRole('status', { name: 'Device login' });
        await expect(login.getByLabel('Device password')).toHaveValue(/^mdv_/);
        device = {
            username: /-u (device-\S+)/.exec(
                await login.getByLabel('Example command').inputValue(),
            )![1],
            password: await login.getByLabel('Device password').inputValue(),
        };
        await shot(devices, 'devices');

        const opcua = page.getByRole('region', { name: 'OPC UA servers' });
        await opcua.getByLabel('Server name').fill('Depot PLC');
        await opcua
            .getByLabel('Endpoint')
            .fill(`opc.tcp://127.0.0.1:${OPCUA_PORT}/meridian`);
        await opcua.getByRole('button', { name: 'Add server' }).click();
        await expect(
            opcua
                .getByRole('list', { name: 'OPC UA servers' })
                .getByText('Depot PLC'),
        ).toBeVisible();
        await expect(page.getByText('Depot PLC added.')).toBeHidden({
            timeout: 15_000,
        });
        await shot(opcua, 'opcua-servers');
        await page.reload();
        await expect(
            page.getByRole('heading', { name: 'Pilot sites' }),
        ).toBeVisible();
        await page.evaluate(() => window.scrollTo(0, 0));
        await shot(page, 'project');
    });

    await test.step('a new twin', async () => {
        await page.getByRole('link', { name: 'New twin' }).click();
        await expect(page).toHaveURL(/\/twins\/create/);
        await expect(page.getByLabel('Project')).toHaveValue(/.+/);
        await page.getByLabel('Name', { exact: true }).fill('Quay scan site');
        await page
            .getByLabel('Description')
            .fill('The quay before the wharf works.');
        await page.getByLabel('Latitude').fill('-36.8442');
        await page.getByLabel('Longitude').fill('174.7676');
        await page.getByLabel('UTC offset (hours)').fill('13');
        await shot(page, 'new-twin');
        await page.goto('/twins');
        await expect(
            page.getByRole('link', { name: 'Harbour Depot' }),
        ).toBeVisible();
        await shot(page, 'twins');
        await page.goto('/projects');
        await page.getByRole('link', { name: /^Pilot sites/ }).click();
        await expect(
            page.getByRole('heading', { name: 'Pilot sites' }),
        ).toBeVisible();
    });

    await test.step('the viewer, on the sample twins', async () => {
        const demo = await context.newPage();
        await demo.goto('/demo');
        await settle(demo);
        await demo.getByRole('button', { name: 'Whole site' }).click();
        await demo.waitForTimeout(3000);
        await shot(demo, 'overview');
        await shot(
            demo.getByRole('region', { name: 'Fire protection' }),
            'panel',
        );

        await demo.locator('.asset-row', { hasText: 'Chiller 2' }).click();
        await demo.waitForTimeout(3000);
        await shot(demo, 'inspector');

        await demo
            .locator('.asset-row', { hasText: 'Fire water tank' })
            .click();
        await demo.waitForTimeout(3000);
        await shot(demo, 'custom-type');

        await demo.locator('.asset-row', { hasText: 'Jockey pump' }).click();
        await demo.waitForTimeout(3000);
        await shot(demo.locator('.inspector'), 'setpoints');

        await demo.getByRole('tab', { name: 'What-if' }).click();
        await demo
            .getByRole('radio', { name: /Refrigeration board trips/ })
            .check();
        await demo.getByRole('button', { name: 'Run forecast' }).click();
        await demo.waitForTimeout(1500);
        await shot(demo, 'what-if');

        await demo
            .getByRole('button', { name: 'Apply to the live twin' })
            .click();
        await demo.getByRole('radio', { name: 'Temp' }).click();
        await demo.getByRole('radio', { name: '600×' }).click();
        await demo.getByRole('tab', { name: 'Alerts' }).click();
        await demo.waitForTimeout(10_000);
        await demo.getByRole('radio', { name: '60×' }).click();
        await shot(demo, 'cascade');
        await demo.close();

        const night = await context.newPage();
        await night.goto('/demo');
        await settle(night);
        await night.getByRole('radio', { name: 'Power' }).click();
        await night.getByRole('radio', { name: '600×' }).click();
        await night.waitForTimeout(18_000);
        await night.getByRole('button', { name: 'Pause' }).click();
        await night.waitForTimeout(1500);
        await shot(night, 'night-power');
        await night.getByRole('button', { name: 'Globe' }).click();
        await night.waitForTimeout(6000);
        await shot(night, 'globe');
        await night.close();

        // From the yard to the freezer hall's roof: along the level, and up.
        const measure = await context.newPage();
        await measure.goto('/demo');
        await settle(measure);
        await measure.getByRole('button', { name: 'Measure' }).click();
        for (const [point, height] of [
            [[20, -150], 0],
            [[-30, -20], 18],
        ] as [[number, number], number][]) {
            const at = await measure.evaluate(
                ([p, h]) =>
                    (
                        window as unknown as {
                            __scene: {
                                screenAt(
                                    p: [number, number],
                                    h: number,
                                ): { x: number; y: number };
                            };
                        }
                    ).__scene.screenAt(p, h),
                [point, height] as const,
            );
            const box = (await measure
                .locator('.globe canvas')
                .first()
                .boundingBox())!;
            await measure.mouse.click(box.x + at.x, box.y + at.y);
        }
        await expect(
            measure.getByRole('region', { name: 'Measure' }).getByText('Up'),
        ).toBeVisible();
        await measure.waitForTimeout(1500);
        await shot(measure, 'measure');
        await measure.close();

        // Low and toward the ridge, so the ground's shape shows on the skyline.
        const lowView = resolve(ROOT, 'test-results/harbour-depot-low.json');
        writeFileSync(
            lowView,
            JSON.stringify({
                ...JSON.parse(
                    readFileSync(
                        resolve(ROOT, 'public/samples/harbour-depot.json'),
                        'utf8',
                    ),
                ),
                camera: { headingDeg: 215, pitchDeg: -6, rangeM: 420 },
            }),
        );
        const depot = await context.newPage();
        await depot.goto('/demo?twin=harbour-depot');
        await settle(depot, 3000);
        await depot.locator('input[type=file]').first().setInputFiles(lowView);
        await settle(depot);
        await expect
            .poll(
                () =>
                    depot.evaluate(
                        () =>
                            (
                                window as unknown as {
                                    __scene: {
                                        terrain(): { loaded: boolean };
                                    };
                                }
                            ).__scene.terrain().loaded,
                    ),
                { timeout: 30_000 },
            )
            .toBe(true);
        await depot.waitForTimeout(4000);
        await shot(depot, 'terrain');
        await shot(depot.locator('.topbar'), 'kpis');
        await depot.close();
    });

    const editor = page.getByRole('complementary', { name: 'Layout' });
    const versionChip = page.locator('.version-chip');
    await test.step('layout and versions', async () => {
        await page.getByRole('link', { name: 'Harbour Depot' }).click();
        await settle(page);
        await page.getByRole('button', { name: 'Layout', exact: true }).click();
        const add = async (name: string, version: number) => {
            await editor
                .getByRole('list', { name: 'Library' })
                .getByRole('listitem')
                .filter({ hasText: name })
                .getByRole('button', { name: 'Add' })
                .click();
            await expect(versionChip).toHaveText(`v${version}`, {
                timeout: 60_000,
            });
        };
        await add('Pump skid', 2);
        await add('Wharf scan', 3);
        await settle(page, 6000);
        await editor.getByLabel('East (m)').first().fill('-38');
        await editor.getByLabel('North (m)').first().fill('-30');
        await editor.getByLabel('Heading (°)').first().fill('30');
        await editor.getByLabel('Scale').first().fill('2');
        await page.waitForTimeout(3000);
        await shot(page, 'layout-editor');
        await editor.getByRole('button', { name: 'Save layout' }).click();
        await expect(versionChip).toHaveText('v4', { timeout: 60_000 });
        await shot(
            editor.locator('section', {
                has: page.getByRole('heading', { name: 'History' }),
            }),
            'versions',
        );

        // The type library: the jockey pump needs Northshore's fire tank.
        const types = editor.locator('section', {
            has: page.getByRole('heading', { name: 'Types', exact: true }),
        });
        await types
            .getByRole('listitem')
            .filter({ hasText: 'Jockey pump' })
            .getByRole('button', { name: 'Add' })
            .click();
        await expect(types.getByRole('alert')).toBeVisible();
        await shot(types, 'twin-types');

        await page.getByRole('button', { name: 'Close layout' }).click();
        await frameLayer(page, 'Wharf scan');
        await page.waitForTimeout(8000);
        await shot(page, 'point-cloud');
    });

    await test.step('telemetry, the gateway and recorded history', async () => {
        await page.getByRole('button', { name: 'Layout', exact: true }).click();
        const telemetry = editor.getByRole('region', { name: 'Telemetry' });
        await telemetry.getByRole('button', { name: 'Add mapping' }).click();
        await telemetry.getByLabel('Topic').fill('depot/store/temp');
        await telemetry
            .getByLabel('Asset')
            .selectOption({ label: 'Seafood store' });
        await telemetry
            .getByLabel('Metric')
            .selectOption({ label: 'Temperature' });
        await telemetry.getByRole('button', { name: 'Save mappings' }).click();
        await expect(versionChip).toHaveText('v5', { timeout: 60_000 });
        await shot(telemetry, 'telemetry');

        // The store's door switch, from the PLC: open is 1, so 100 %.
        const opcuaMappings = editor.getByRole('region', { name: 'OPC UA' });
        await opcuaMappings
            .getByRole('button', { name: 'Add OPC UA mapping' })
            .click();
        await opcuaMappings.getByLabel('Node').fill('ns=1;s=Store.DoorOpen');
        await opcuaMappings
            .getByLabel('Asset')
            .selectOption({ label: 'Seafood store' });
        await opcuaMappings
            .getByLabel('Metric')
            .selectOption({ label: 'Door open' });
        await opcuaMappings.getByLabel('Scale').fill('100');
        await opcuaMappings.getByLabel('Sampling, ms').fill('500');
        await opcuaMappings
            .getByRole('button', { name: 'Save OPC UA mappings' })
            .click();
        await expect(versionChip).toHaveText('v6', { timeout: 60_000 });
        await shot(opcuaMappings, 'opcua-mappings');

        const gatewayPanel = editor.locator('section', {
            has: page.getByRole('heading', { name: 'Gateway' }),
        });
        await gatewayPanel.getByRole('button', { name: 'Issue token' }).click();
        const command = await gatewayPanel
            .getByLabel('Gateway command')
            .inputValue();
        await shot(gatewayPanel, 'gateway-token');
        const token = /MERIDIAN_GATEWAY_TOKEN=(\S+)/.exec(command)![1];

        const log = openSync(
            resolve(ROOT, 'test-results/screenshots-gateway.log'),
            'w',
        );
        processes.push(
            spawn(process.execPath, ['--import', 'tsx', 'gateway/gateway.ts'], {
                cwd: ROOT,
                env: {
                    ...process.env,
                    MERIDIAN_URL: 'http://127.0.0.1:8199',
                    MERIDIAN_GATEWAY_TOKEN: token,
                    MQTT_URL: 'mqtt://127.0.0.1:1883',
                    GATEWAY_PORT: String(GATEWAY_PORT),
                    GATEWAY_PUBLIC_URL: `ws://127.0.0.1:${GATEWAY_PORT}/ws`,
                    GATEWAY_POLL_MS: '2000',
                    OPCUA_PKI_DIR: resolve(ROOT, 'test-results/opcua-pki'),
                },
                stdio: ['ignore', log, log],
            }),
        );
        await expect
            .poll(
                () =>
                    fetch(`http://127.0.0.1:${GATEWAY_PORT}/health`).then(
                        (r) => (r.ok ? r.json() : null),
                        () => null,
                    ),
                { timeout: 60_000 },
            )
            .toMatchObject({
                mqtt: { connected: true },
                opcua: { servers: { 'Depot PLC': { status: 'connected' } } },
            });

        // The store holds about 1 °C, with a door left open for an hour and a half ten hours ago.
        backfill(
            'Harbour Depot',
            'store',
            'tempC',
            Array.from(
                { length: 144 },
                (_, i) =>
                    Math.round(
                        (1.1 +
                            0.4 * Math.sin(i / 9) +
                            (i > 60 && i < 70 ? 2.9 : 0)) *
                            100,
                    ) / 100,
            ),
        );
        for (const value of ['1.3', '1.2', '1.2']) {
            run('docker', [
                'exec',
                BROKER,
                'mosquitto_pub',
                '-h',
                '127.0.0.1',
                '-u',
                device.username,
                '-P',
                device.password,
                '-t',
                `${await prefix(page)}depot/store/temp`,
                '-m',
                value,
            ]);
        }
        await expect
            .poll(
                () =>
                    fetch(`http://127.0.0.1:${GATEWAY_PORT}/health`)
                        .then((r) => r.json())
                        .then(
                            (health: { history: { sent: number } }) =>
                                health.history.sent,
                        ),
                { timeout: 30_000 },
            )
            // The three MQTT messages, and the door from OPC UA.
            .toBeGreaterThanOrEqual(4);

        await expect(async () => {
            await page.reload();
            await expect(page.getByRole('radio', { name: 'Live' })).toBeChecked(
                { timeout: 5000 },
            );
        }).toPass({ timeout: 60_000 });
        await settle(page, 6000);
        await page.locator('.asset-row', { hasText: 'Seafood store' }).click();
        await page.waitForTimeout(3000);
        await shot(page, 'recorded');

        await page.getByRole('button', { name: 'Layout', exact: true }).click();
        await shot(gatewayPanel, 'gateway-online');
    });

    await test.step('a BIM model, the definition editor and drawing', async () => {
        await page.goto('/projects');
        await page.getByRole('link', { name: /^Pilot sites/ }).click();
        await page
            .getByLabel(/^File \(/)
            .setInputFiles(resolve(ROOT, 'test-results/shed.ifc'));
        await page.getByLabel('Name', { exact: true }).fill('Depot shed');
        await page.getByRole('button', { name: 'Upload', exact: true }).click();
        const shed = library
            .getByRole('listitem')
            .filter({ hasText: 'Depot shed' });
        await expect(shed).toContainText('Converting', { timeout: 60_000 });
        convertOne('ifc');
        await expect(shed).toContainText('BIM · 6 elements', {
            timeout: 300_000,
        });
        await shot(shed, 'library-ifc');

        // A twin of its own, where the shed's site says it is.
        await page.getByRole('link', { name: 'New twin' }).click();
        await expect(page.getByLabel('Project')).toHaveValue(/.+/);
        await page.getByLabel('Name', { exact: true }).fill('Depot shed');
        await page.getByLabel('Latitude').fill(String(SHED[0]));
        await page.getByLabel('Longitude').fill(String(SHED[1]));
        await page.getByRole('button', { name: 'Create twin' }).click();
        await expect(page.locator('.twin-name')).toHaveText('Depot shed');
        await settle(page, 4000);
        await page.getByRole('button', { name: 'Layout', exact: true }).click();
        await editor
            .getByRole('list', { name: 'Library' })
            .getByRole('listitem')
            .filter({ hasText: 'Depot shed' })
            .getByRole('button', { name: 'Add' })
            .click();
        await expect(versionChip).toHaveText('v2', { timeout: 60_000 });
        await page.getByRole('button', { name: 'Close layout' }).click();
        await settle(page, 6000);
        await frameModel(page, 'Depot shed', 0, -0.75, 140);

        const definition = page.getByRole('complementary', {
            name: 'Definition',
        });
        await page
            .getByRole('button', { name: 'Definition', exact: true })
            .click();
        await definition.getByRole('button', { name: 'Add zone' }).click();
        const zone = definition.getByRole('form', { name: 'Edit zone' });
        await zone.getByLabel('Name').fill('Shed yard');
        await zone.getByRole('button', { name: 'Draw in scene' }).click();
        await zone.getByRole('button', { name: 'Start again' }).click();
        for (const p of [
            [-6, -6],
            [26, -6],
            [26, 18],
            [-6, 18],
        ] as [number, number][]) {
            await clickGround(page, p);
        }
        await page.waitForTimeout(1500);
        await shot(page, 'definition-drawing');
        await zone.getByRole('button', { name: 'Done drawing' }).click();

        await definition.getByRole('tab', { name: 'Assets' }).click();
        await definition
            .getByRole('list', { name: 'Assets' })
            .getByRole('button', { name: /^Main board/ })
            .click();
        const board = definition.getByRole('form', { name: 'Edit board' });
        const feature = board.getByRole('group', { name: 'Model feature' });
        // From the west, where the afternoon sun lights the shed's end wall.
        await frameModel(page, 'Depot shed', 100, -0.3, 60);
        await feature.getByRole('button', { name: 'Pick in scene' }).click();
        await clickGround(page, [2, 6]);
        await expect(feature.getByLabel('Feature')).not.toHaveValue('');
        await expect(definition.getByText('No problems.')).toBeVisible();
        await feature.scrollIntoViewIfNeeded();
        await shot(page, 'definition-editor');
        await definition
            .getByRole('button', { name: 'Save', exact: true })
            .click();
        await expect(versionChip).toHaveText('v3', { timeout: 60_000 });

        await settle(page, 8000);
        await frameModel(page, 'Depot shed', 100, -0.3, 60);
        await clickGround(page, [2, 6]);
        await expect(
            page.getByRole('heading', { name: 'Main board' }),
        ).toBeVisible();
        await page.waitForTimeout(2000);
        await shot(page, 'feature-bound');
    });

    await test.step('writing setpoints to OPC UA', async () => {
        page.once('dialog', (dialog) => void dialog.accept());
        await page.goto('/projects');
        await page.getByRole('link', { name: /^Pilot sites/ }).click();
        await page
            .getByRole('link', { name: 'Northshore Cold Chain Hub' })
            .click();
        await settle(page);
        const version = Number((await versionChip.innerText()).slice(1));
        await page.getByRole('button', { name: 'Layout', exact: true }).click();

        const opcua = editor.getByRole('region', { name: 'OPC UA' });
        const gatewayPanel = editor.locator('section', {
            has: page.getByRole('heading', { name: 'Gateway' }),
        });
        await gatewayPanel.getByRole('button', { name: 'Issue token' }).click();
        const token = /MERIDIAN_GATEWAY_TOKEN=(\S+)/.exec(
            await gatewayPanel.getByLabel('Gateway command').inputValue(),
        )![1];
        const port = GATEWAY_PORT + 1;
        const health = () =>
            fetch(`http://127.0.0.1:${port}/health`).then(
                (r) => (r.ok ? r.json() : null),
                () => null,
            );
        const log = openSync(
            resolve(ROOT, 'test-results/screenshots-gateway-northshore.log'),
            'w',
        );
        processes.push(
            spawn(process.execPath, ['--import', 'tsx', 'gateway/gateway.ts'], {
                cwd: ROOT,
                env: {
                    ...process.env,
                    MERIDIAN_URL: 'http://127.0.0.1:8199',
                    MERIDIAN_GATEWAY_TOKEN: token,
                    GATEWAY_PORT: String(port),
                    GATEWAY_PUBLIC_URL: `ws://127.0.0.1:${port}/ws`,
                    GATEWAY_POLL_MS: '2000',
                    OPCUA_PKI_DIR: resolve(ROOT, 'test-results/opcua-pki'),
                },
                stdio: ['ignore', log, log],
            }),
        );
        await expect
            .poll(health, { timeout: 60_000 })
            .toMatchObject({ status: 'ok', version });
        const goLive = () =>
            expect(async () => {
                await page.reload();
                await expect(
                    page.getByRole('radio', { name: 'Live' }),
                ).toBeChecked({ timeout: 5000 });
            }).toPass({ timeout: 60_000 });
        await goLive();
        await page.getByRole('button', { name: 'Layout', exact: true }).click();

        // Find the jockey pump's start pressure on the PLC, held in bar.
        const nodeBrowser = opcua.getByRole('group', {
            name: 'OPC UA browser',
        });
        await nodeBrowser.getByLabel('Look in').selectOption('Depot PLC');
        await nodeBrowser.getByRole('button', { name: 'Browse' }).click();
        await nodeBrowser.getByRole('button', { name: 'Site ›' }).click();
        await expect(nodeBrowser.getByText('Pump.StartBar')).toBeVisible();
        await shot(nodeBrowser, 'opcua-browser');
        await nodeBrowser
            .getByRole('button', { name: 'Map ns=1;s=Pump.StartBar' })
            .click();
        await opcua.getByLabel('Asset').selectOption({ label: 'Jockey pump' });
        await opcua.getByLabel('Metric').selectOption({ label: 'Start below' });
        await opcua.getByLabel('Scale').fill('100');
        await opcua.getByLabel('Sampling, ms').fill('500');
        await opcua.getByLabel('Write setpoints').check();
        await opcua
            .getByRole('button', { name: 'Save OPC UA mappings' })
            .click();
        await expect(versionChip).toHaveText(`v${version + 1}`, {
            timeout: 60_000,
        });
        const allowed = opcua.getByLabel(
            "Write operators' setpoints to OPC UA",
        );
        await allowed.click();
        await expect(allowed).toBeChecked();
        await expect.poll(health, { timeout: 60_000 }).toMatchObject({
            opcua: {
                writes: true,
                servers: { 'Depot PLC': { status: 'connected' } },
            },
        });
        await goLive();
        await settle(page, 4000);
        // The PLC's 6 bar reaches the twin as 600 kPa.
        await expect
            .poll(
                () =>
                    page.evaluate(
                        () =>
                            (
                                window as unknown as {
                                    __twin: {
                                        getState(): {
                                            live?: {
                                                values: Record<string, number>;
                                            };
                                        };
                                    };
                                }
                            ).__twin.getState().live?.values['jockey.startKpa'],
                    ),
                { timeout: 60_000 },
            )
            .toBe(600);
        await page.locator('.asset-row', { hasText: 'Jockey pump' }).click();
        // 600 kPa, read from the PLC, up to 630.
        const raise = page.getByRole('button', { name: 'Raise Start below' });
        for (const _ of [1, 2, 3]) await raise.click();
        await page
            .locator('.control-row', { hasText: 'Start below' })
            .getByRole('button', { name: 'Apply' })
            .click();
        await expect
            .poll(
                () =>
                    fetch(`http://127.0.0.1:${port}/health`)
                        .then((r) => r.json())
                        .then(
                            (health: { opcua: { written: number } }) =>
                                health.opcua.written,
                        ),
                { timeout: 30_000 },
            )
            .toBe(1);

        await page.reload();
        await page.getByRole('button', { name: 'Layout', exact: true }).click();
        await expect(
            opcua.getByRole('list', { name: 'Recent OPC UA writes' }),
        ).toContainText('Jockey pump Start below = 630');
        await shot(
            opcua.getByRole('group', { name: 'Writing setpoints' }),
            'opcua-writes',
        );
    });

    await test.step('members and account', async () => {
        await page.goto('/organisation/members');
        await expect(page.getByText('viewer@example.com')).toBeVisible();
        await shot(page, 'members');
        await page.goto('/organisation/types');
        await expect(page.getByText('Jockey pump')).toBeVisible();
        await shot(page, 'types');
        await page.goto('/settings/two-factor');
        await page.getByLabel('Password', { exact: true }).fill('password');
        await page.getByRole('button', { name: 'Confirm password' }).click();
        await expect(page).toHaveURL(/\/settings\/two-factor/);
        await page.waitForTimeout(1000);
        await shot(page, 'security');
    });
});

/**
 * Clicks a point on the ground, east and north of the twin's origin, once
 * the camera has stopped moving.
 */
async function clickGround(page: Page, point: [number, number]) {
    type Scene = {
        __scene: {
            screenAt(p: [number, number]): { x: number; y: number } | null;
        };
    };
    const at = () =>
        page.evaluate(
            (p) => (window as unknown as Scene).__scene.screenAt(p),
            point,
        );
    let last = '';
    await expect
        .poll(
            async () => {
                const now = JSON.stringify(await at());
                const settled = now === last;
                last = now;
                return settled;
            },
            { timeout: 30_000, intervals: [500] },
        )
        .toBe(true);
    const spot = await at();
    if (!spot) throw new Error(`${point.join(', ')} is off screen`);
    const box = (await page.locator('canvas').first().boundingBox())!;
    await page.mouse.click(box.x + spot.x, box.y + spot.y);
}

/** The project's topic prefix, as the Telemetry section shows it. */
async function prefix(page: Page): Promise<string> {
    const text = await page
        .getByRole('region', { name: 'Telemetry' })
        .locator('code')
        .first()
        .innerText();
    return text;
}

/** Points the camera at a placed model, from a heading in degrees, a pitch in radians and a range in metres. */
async function frameModel(
    page: Page,
    name: string,
    headingDeg: number,
    pitch: number,
    range: number,
) {
    await page.evaluate(
        ([layer, heading, tilt, distance]) => {
            const w = window as unknown as {
                __scene: {
                    layerPrimitives: Map<string, { boundingSphere: unknown }>;
                    viewer: {
                        camera: {
                            flyToBoundingSphere(
                                sphere: unknown,
                                options: unknown,
                            ): void;
                        };
                    };
                };
                __twin: {
                    getState(): {
                        def: { models?: { id: string; name: string }[] };
                    };
                };
            };
            const id = w.__twin
                .getState()
                .def.models!.find((m) => m.name === layer)!.id;
            w.__scene.viewer.camera.flyToBoundingSphere(
                w.__scene.layerPrimitives.get(id)!.boundingSphere,
                {
                    duration: 0,
                    offset: {
                        heading: ((heading as number) * Math.PI) / 180,
                        pitch: tilt,
                        range: distance,
                    },
                },
            );
        },
        [name, headingDeg, pitch, range] as const,
    );
}

/** Points the camera at a layer, from the twin's own heading. */
async function frameLayer(page: Page, name: string) {
    await page.evaluate((layer) => {
        type Scene = {
            layerPrimitives: Map<string, { boundingSphere: unknown }>;
            viewer: {
                camera: {
                    flyToBoundingSphere(
                        sphere: unknown,
                        options: unknown,
                    ): void;
                };
            };
        };
        const w = window as unknown as {
            __scene: Scene;
            __twin: {
                getState(): {
                    def: {
                        tilesets?: { id: string; name: string }[];
                        camera: { headingDeg: number };
                    };
                };
            };
        };
        const def = w.__twin.getState().def;
        const id = def.tilesets!.find((t) => t.name === layer)!.id;
        w.__scene.viewer.camera.flyToBoundingSphere(
            w.__scene.layerPrimitives.get(id)!.boundingSphere,
            {
                duration: 0,
                offset: {
                    heading: ((def.camera.headingDeg - 25) * Math.PI) / 180,
                    pitch: -0.55,
                    range: 210,
                },
            },
        );
    }, name);
}

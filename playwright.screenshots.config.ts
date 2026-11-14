import { defineConfig } from '@playwright/test';
import e2e from './playwright.config.ts';

/**
 * Takes the README's and the manual's screenshots (docs/images) on the same
 * freshly seeded app as the e2e tests, with a real MQTT broker and the point
 * cloud converter run in Docker. Run `npm run build`, then
 * `npm run screenshots`.
 */
const servers = Array.isArray(e2e.webServer) ? e2e.webServer : [];

export default defineConfig({
    ...e2e,
    testDir: 'tests/screenshots',
    timeout: 1_800_000,
    use: { ...e2e.use, colorScheme: 'dark' },
    webServer: servers.map((server, i) =>
        i === 0
            ? {
                  ...server,
                  env: {
                      ...server.env,
                      MERIDIAN_MQTT_AUTH_SECRET: 'screenshots',
                      MERIDIAN_MQTT_BROKER_URL: 'mqtt://localhost:1883',
                  },
              }
            : server,
    ),
});

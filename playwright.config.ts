import { resolve } from 'node:path';
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: 'tests/e2e',
  timeout: 180_000,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:8199',
    channel: 'chrome',
    viewport: { width: 1600, height: 950 },
    launchOptions: {
      // Software WebGL, so this runs on machines and CI with no GPU.
      args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist'],
    },
  },
  webServer: [
    {
      // Serves the built assets (npm run build) through Laravel, on a fresh,
      // seeded database of its own. PHP's own server is used because artisan
      // serve does not pass DB_DATABASE through to its workers.
      command:
        'touch database/e2e.sqlite && php artisan migrate:fresh --seed --force -q && ' +
        'cd public && php -d upload_max_filesize=64M -d post_max_size=64M -S 127.0.0.1:8199 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
      // Notifications are delivered as they're queued, and may reach a receiver on this machine.
      env: {
        DB_DATABASE: resolve('database/e2e.sqlite'),
        APP_URL: 'http://127.0.0.1:8199',
        SESSION_DRIVER: 'file',
        CACHE_STORE: 'file',
        MERIDIAN_NOTIFICATIONS_QUEUE: 'sync',
        MAIL_MAILER: 'array',
        MERIDIAN_ALLOW_PRIVATE_WEBHOOKS: 'true',
      },
      url: 'http://127.0.0.1:8199/up',
      reuseExistingServer: false,
      timeout: 120_000,
    },
    {
      command: 'npx tsx gateway/gateway.ts',
      env: { GATEWAY_PORT: '8799' },
      url: 'http://127.0.0.1:8799/health',
      reuseExistingServer: false,
      timeout: 30_000,
    },
  ],
});

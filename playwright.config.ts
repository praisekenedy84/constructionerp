import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    timeout: 60_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list'], ['html', { open: 'never' }], ['json', { outputFile: 'playwright-report/results.json' }]],
    use: {
        baseURL: 'http://127.0.0.1:8015',
        headless: process.env.PLAYWRIGHT_HEADLESS === '1',
        viewport: { width: 1440, height: 1000 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    webServer: {
        command: 'node tests/Browser/server.mjs',
        url: 'http://127.0.0.1:8015/login',
        reuseExistingServer: false,
        timeout: 180_000,
    },
});

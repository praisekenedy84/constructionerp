import { test, expect } from '@playwright/test';
import { writeFileSync } from 'node:fs';

test('measure initial loading and authenticated pages', async ({ page }) => {
    test.setTimeout(120_000);
    const measurements: Record<string, unknown>[] = [];
    for (const path of ['/login', '/login', '/login']) {
        const start = performance.now();
        const response = await page.goto(path);
        expect(response?.status()).toBe(200);
        await expect(page.getByLabel('Username or email')).toBeVisible();
        measurements.push({ path, readyMs: Math.round(performance.now() - start), ...await page.evaluate(() => {
            const navigation = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming;
            return { ttfbMs: Math.round(navigation.responseStart - navigation.requestStart), htmlBytes: navigation.decodedBodySize };
        }) });
    }
    await page.getByLabel('Username or email').fill('browser.admin');
    await page.getByLabel('Password', { exact: true }).fill('BrowserPass123!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    for (const path of ['/dashboard', '/projects', '/requisitions', '/dashboard', '/projects', '/requisitions', '/dashboard', '/projects', '/requisitions']) {
        const start = performance.now();
        const response = await page.goto(path);
        expect(response?.status()).toBe(200);
        await expect(page.locator('main')).toBeVisible();
        measurements.push({ path, readyMs: Math.round(performance.now() - start), ...await page.evaluate(() => {
            const navigation = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming;
            return { ttfbMs: Math.round(navigation.responseStart - navigation.requestStart), htmlBytes: navigation.decodedBodySize };
        }) });
    }
    await page.goto('/dashboard');
    await expect(page.locator('main .recharts-wrapper')).toHaveCount(2);
    await expect(page.locator('main .recharts-bar')).toHaveCount(2);
    await expect(page.locator('main .recharts-pie')).toHaveCount(1);
    const label = process.env.PERFORMANCE_LABEL === 'baseline' ? 'baseline' : 'optimized';
    writeFileSync(`storage/app/playwright/performance-${label}.json`, JSON.stringify(measurements, null, 2));
    await test.info().attach(`performance ${label}`, { body: JSON.stringify(measurements, null, 2), contentType: 'application/json' });
});

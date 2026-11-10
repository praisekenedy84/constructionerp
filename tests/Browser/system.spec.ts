import { test as base, expect, Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

const password = 'BrowserPass123!';
const fixture = () => JSON.parse(readFileSync('storage/app/playwright/manifest.json', 'utf8'));
const test = base.extend<{ browserHealth: void }>({
    browserHealth: [async ({ page }, use) => {
        const errors: string[] = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('response', response => {
            if (response.status() >= 500 && response.url().startsWith('http://127.0.0.1:8015')) errors.push(`${response.status()} ${response.url()}`);
        });
        await use();
        expect(errors, 'No JavaScript exceptions or server errors').toEqual([]);
    }, { auto: true }],
});

async function login(page: Page, username = 'browser.admin', pass = password) {
    await page.goto('/login');
    await page.getByLabel('Username or email').fill(username);
    await page.getByLabel('Password', { exact: true }).fill(pass);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/(dashboard|auth\/companies)$/);
    if (page.url().endsWith('/auth/companies')) {
        await page.getByRole('button', { name: /Enter workspace/ }).click();
    }
    await expect(page).toHaveURL(/\/dashboard$/);
}

async function platformLogin(page: Page, pass = password) {
    await page.goto('/platform/login');
    await page.getByLabel('Email').fill('platform@browser.test');
    await page.getByLabel('Password', { exact: true }).fill(pass);
    await page.getByRole('button', { name: 'Sign in to platform' }).click();
    await expect(page).toHaveURL(/\/platform\/?$/);
}

test('platform: tenant lifecycle, user editing, password reset, locking and deletion', async ({ page }) => {
    test.setTimeout(150_000);
    await platformLogin(page);
    await page.goto('/platform/tenants/create');
    const inputs = page.locator('main form input');
    for (const [index, value] of ['Browser Disposable', 'browser-disposable', 'Disposable Admin', 'disposable.admin', 'disposable@browser.test', password, password].entries()) {
        await inputs.nth(index).fill(value);
    }
    await page.locator('main form button[type=submit]').click();
    await expect(page).toHaveURL(/\/platform\/tenants\/[^/]+$/);
    const tenantUrl = page.url();
    await page.getByLabel('Company name').fill('Browser Updated Company');
    await page.getByLabel('Company slug').fill('browser-updated');
    await page.getByRole('button', { name: 'Save tenant details' }).click();
    await expect(page.getByRole('heading', { name: 'Browser Updated Company', exact: true, level: 2 })).toBeVisible();

    await page.getByRole('button', { name: 'Add user', exact: true }).click();
    let dialog = page.getByRole('dialog');
    await dialog.getByLabel('Name', { exact: true }).fill('Browser Operator');
    await dialog.getByLabel('Username', { exact: true }).fill('browser.operator');
    await dialog.getByLabel('Email', { exact: true }).fill('operator@browser.test');
    await dialog.getByLabel('Role', { exact: true }).selectOption('Site Engineer');
    await dialog.getByLabel('Password', { exact: true }).fill(password);
    await dialog.getByLabel('Confirm password', { exact: true }).fill(password);
    await dialog.getByRole('button', { name: 'Create user' }).click();
    await expect(dialog).not.toBeVisible();
    let row = page.getByRole('row').filter({ hasText: 'browser.operator' });
    await row.getByRole('button', { name: 'Edit user / password' }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('Name', { exact: true }).fill('Browser Edited Operator');
    await dialog.getByRole('button', { name: 'Save changes' }).click();
    await expect(dialog).not.toBeVisible();
    row = page.getByRole('row').filter({ hasText: 'browser.operator' });
    await expect(row).toContainText('Browser Edited Operator');
    await row.getByRole('button', { name: 'Update password', exact: true }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('New password', { exact: true }).fill('UpdatedBrowser123!');
    await dialog.getByLabel('Confirm new password').fill('Mismatch123!');
    await dialog.getByRole('button', { name: 'Update password', exact: true }).click();
    await expect(dialog).toContainText(/confirmation.*match/i);
    await dialog.getByLabel('New password', { exact: true }).fill('UpdatedBrowser123!');
    await dialog.getByLabel('Confirm new password').fill('UpdatedBrowser123!');
    await dialog.getByRole('button', { name: 'Update password', exact: true }).click();
    await expect(dialog).not.toBeVisible();

    page.once('dialog', dialog => dialog.accept('Browser test lock'));
    await row.getByRole('button', { name: 'Lock', exact: true }).click();
    await expect(row).toContainText('Locked');
    page.once('dialog', dialog => dialog.accept());
    await row.getByRole('button', { name: 'Unlock', exact: true }).click();
    await expect(row).toContainText('Active');

    // Verify the changed password in a separate tenant browser session.
    const context = await page.context().browser()!.newContext();
    const userPage = await context.newPage();
    await login(userPage, 'browser.operator', 'UpdatedBrowser123!');
    await context.close();

    await page.getByRole('button', { name: 'Suspend tenant', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm suspend' }).click();
    await expect(page.getByRole('button', { name: 'Reactivate tenant' })).toBeVisible();
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Reactivate tenant' }).click();
    await expect(page.getByRole('button', { name: 'Suspend tenant', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Delete tenant', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Permanently delete tenant' })).toBeDisabled();
    await page.getByLabel('Type browser-updated to confirm').fill('browser-updated');
    await page.getByRole('button', { name: 'Permanently delete tenant' }).click();
    await expect(page).toHaveURL(/\/platform\/tenants$/);
    await expect(page.getByText('Browser Updated Company', { exact: true })).toHaveCount(0);
    expect(tenantUrl).toContain('/platform/tenants/');
});

test('platform: own password validation and successful sign-in with changed password', async ({ page }) => {
    await platformLogin(page);
    await page.getByRole('link', { name: 'Change password', exact: true }).click();
    await page.getByLabel('Current password', { exact: true }).fill('incorrect');
    await page.getByLabel('New password', { exact: true }).fill('ChangedPlatform123!');
    await page.getByLabel('Confirm new password').fill('ChangedPlatform123!');
    await page.getByRole('button', { name: 'Update password' }).click();
    await expect(page.locator('main')).toContainText(/password.*incorrect/i);
    await page.getByLabel('Current password', { exact: true }).fill(password);
    await page.getByLabel('New password', { exact: true }).fill('ChangedPlatform123!');
    await page.getByLabel('Confirm new password').fill('ChangedPlatform123!');
    await page.getByRole('button', { name: 'Update password' }).click();
    await expect(page.getByRole('status')).toContainText('Password updated.');
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await platformLogin(page, 'ChangedPlatform123!');
    await page.goto('/platform/password');
    await page.getByLabel('Current password', { exact: true }).fill('ChangedPlatform123!');
    await page.getByLabel('New password', { exact: true }).fill(password);
    await page.getByLabel('Confirm new password').fill(password);
    await page.getByRole('button', { name: 'Update password' }).click();
    await expect(page.getByRole('status')).toContainText('Password updated.');
});

test.describe('ERP module pages', () => {
    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage({ storageState: { cookies: [], origins: [] } });
        await login(page);
        await page.context().storageState({ path: 'storage/app/playwright/admin-state.json' });
        await page.context().close();
    });
    test.use({ storageState: 'storage/app/playwright/admin-state.json' });

    const routes = ['/dashboard', '/projects', '/projects/create', '/requisitions', '/requisitions/categories', '/requisitions/units', '/recipients', '/recipients/attendance', '/requisitions/create', '/requisitions/review-queue', '/requisitions/fulfill-queue', '/requisitions/fulfilled', '/approvals/steps', '/sales', '/invoices', '/finance/overview', '/finance/accounts', '/finance/debts', '/finance/manager-transactions', '/finance/finance-transactions', '/finance/approvals', '/finance/organization-cash', '/finance/expenses', '/finance/overhead', '/finance/income-statement', '/procurement/suppliers', '/procurement/purchase-orders', '/procurement/goods-receipts', '/inventory/items', '/inventory/balances', '/inventory/issues', '/inventory/transactions', '/payroll/generate', '/payroll/runs', '/payroll/employees', '/payroll/attendance', '/equipment', '/equipment/assignments', '/equipment/maintenance', '/equipment/fuel', '/reports', '/audit', '/settings/ui', '/admin/users', '/admin/staff', '/admin/menu', '/admin/permissions'];
    for (const path of [...routes, '/projects/compliance-rules', '/requisitions/departments', '/requisitions/positions', '/reports/schedules', '/notifications', '/finance', '/procurement', '/inventory', '/payroll', '/admin/settings']) test(`renders ${path}`, async ({ page }) => {
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(200);
        await expect(page.locator('main')).toBeVisible();
        await expect(page.locator('main')).not.toHaveText('');
        await expect(page).not.toHaveURL(/\/login$/);
    });

    for (const suffix of ['', '/edit', '/boq', '/boq/create', '/boq/import', '/budget', '/valuations', '/valuations/create']) test(`project detail ${suffix || '/'}`, async ({ page }) => {
        const response = await page.goto(`/projects/${fixture().projectId}${suffix}`);
        expect(response?.status()).toBe(200);
        await expect(page.locator('main')).toBeVisible();
    });

    test('requisition detail pages', async ({ page }) => {
        for (const requisition of fixture().requisitions) {
            const response = await page.goto(`/requisitions/${requisition.id}`);
            expect(response?.status(), `requisition ${requisition.id}`).toBe(200);
            await expect(page.locator('main')).toBeVisible();
        }
    });

    test('create and edit a project through browser forms', async ({ page }) => {
        await page.goto('/projects/create');
        await page.getByLabel('Project Code', { exact: true }).fill('BROWSER-001');
        await page.getByLabel('Project Name', { exact: true }).fill('Browser Created Project');
        await page.getByLabel('Contract Amount (TZS)', { exact: true }).fill('1000000');
        await page.getByLabel('Client Name', { exact: true }).fill('Browser Client');
        await page.getByLabel('Phone Number', { exact: true }).fill('+254700123456');
        await page.getByLabel('TIN', { exact: true }).fill('BROWSER-TAX-001');
        await page.getByLabel('Location', { exact: true }).fill('Nairobi');
        await page.getByLabel('Start Date', { exact: true }).fill('2026-11-10');
        await page.getByLabel('End Date', { exact: true }).fill('2027-11-10');
        await page.locator('main form button[type=submit]').click();
        await expect(page).not.toHaveURL(/\/projects\/create$/);
        await page.goto('/projects');
        const row = page.getByRole('row').filter({ hasText: 'BROWSER-001' });
        await expect(row).toContainText('Browser Created Project');
        const href = await row.locator('a[href*="/projects/"]').first().getAttribute('href');
        expect(href).toBeTruthy();
        const projectId = href!.match(/projects\/(\d+)/)![1];
        await page.goto(`/projects/${projectId}/edit`);
        await page.getByLabel('Project Name', { exact: true }).fill('Browser Edited Project');
        await page.locator('main form button[type=submit]').click();
        await page.goto('/projects');
        await expect(page.getByRole('row').filter({ hasText: 'BROWSER-001' })).toContainText('Browser Edited Project');
    });

    test('mobile dashboard navigation', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/dashboard');
        await expect(page.locator('main')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        const screenshot = await page.screenshot({ fullPage: true });
        await test.info().attach('mobile dashboard', { body: screenshot, contentType: 'image/png' });
    });

    test('procurement: create supplier', async ({ page }) => {
        await page.goto('/procurement/suppliers');
        await page.getByRole('button', { name: 'Add Supplier', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Name', { exact: true }).fill('Browser Test Supplier');
        await dialog.getByLabel('Phone', { exact: true }).fill('+254700123456');
        await dialog.getByLabel('Email', { exact: true }).fill('supplier@browser.test');
        await dialog.getByRole('button', { name: 'Add Supplier', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: 'Browser Test Supplier' })).toBeVisible();
    });

    test('inventory: create item with opening stock', async ({ page }) => {
        await page.goto('/inventory/items');
        await page.getByRole('button', { name: 'New Item', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Name', { exact: true }).fill('Browser Cement Bags');
        await dialog.getByLabel('Unit', { exact: true }).fill('bag');
        await dialog.getByLabel('Quantity', { exact: true }).fill('20');
        await dialog.getByLabel('Location', { exact: true }).selectOption({ index: 1 });
        await dialog.getByLabel('Unit cost (optional)', { exact: true }).fill('12000');
        await dialog.getByRole('button', { name: 'Create & stock', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: 'Browser Cement Bags' })).toBeVisible();
        await page.goto('/inventory/balances');
        await expect(page.getByRole('row').filter({ hasText: 'Browser Cement Bags' })).toContainText('20');
    });

    test('equipment: register an asset', async ({ page }) => {
        await page.goto('/equipment');
        await page.getByRole('button', { name: 'Register Equipment', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Name', { exact: true }).fill('Browser Excavator');
        await dialog.getByLabel('Type', { exact: true }).fill('Excavator');
        await dialog.getByRole('button', { name: 'Register', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: 'Browser Excavator' })).toBeVisible();
    });

    test('budget: post a manual adjustment', async ({ page }) => {
        await page.goto(`/projects/${fixture().projectId}/budget`);
        await page.getByRole('button', { name: 'Post Adjustment', exact: true }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Amount (TZS)', { exact: true }).fill('50000');
        await dialog.getByLabel('Reason', { exact: true }).fill('Browser budget adjustment');
        await dialog.getByRole('button', { name: 'Post Adjustment', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: 'Browser budget adjustment' })).toBeVisible();
    });

    test('requisition: create a draft and publish for approval', async ({ page }) => {
        await page.goto('/requisitions/create');
        const field = (label: string) => page.locator('label').filter({ hasText: new RegExp(`^${label}$`) }).locator('..');
        await field('Project').locator('select').selectOption(String(fixture().projectId));
        await field('Recipient').locator('select').selectOption({ index: 1 });
        await page.getByPlaceholder('What is needed').fill('Browser safety supplies');
        await field('Unit').locator('select').selectOption({ index: 1 });
        await field('Qty').locator('input').fill('2');
        await field('Unit cost').locator('input').fill('1000');
        await page.getByRole('button', { name: 'Create Requisition', exact: true }).click();
        await expect(page).toHaveURL(/\/requisitions\/\d+$/);
        await expect(page.locator('main')).toContainText('Browser safety supplies');
        await page.getByRole('button', { name: 'Publish for Approval', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Publish for Approval', exact: true })).toHaveCount(0);
        await expect(page.locator('main')).toContainText(/under.review/i);
    });

    test('payroll: preview a pay period', async ({ page }) => {
        await page.goto(`/payroll/generate?project_id=${fixture().projectId}`);
        const dialog = page.getByRole('dialog');
        await dialog.getByLabel('Period Start', { exact: true }).fill('2026-11-01');
        await dialog.getByLabel('Period End', { exact: true }).fill('2026-11-10');
        await dialog.getByRole('button', { name: 'Preview Payroll', exact: true }).click();
        await expect(dialog).not.toBeVisible();
        await expect(page.getByRole('heading', { name: 'Payroll Preview', exact: true })).toBeVisible();
    });

    test('reports: open each linked preview', async ({ page }) => {
        test.setTimeout(120_000);
        await page.goto('/reports');
        const links = await page.locator('main a[href*="/reports/preview/"]').evaluateAll(elements => elements.map(element => element.getAttribute('href')!));
        expect(links.length).toBeGreaterThan(0);
        for (const href of [...new Set(links)]) {
            const response = await page.goto(href);
            expect(response?.status(), href).toBe(200);
            await expect(page.locator('main')).toBeVisible();
        }
    });
});

test('role restrictions: site engineer cannot access platform or user administration', async ({ page }) => {
    await login(page, 'engineer@demo.local', 'password');
    await page.goto('/platform/tenants');
    await expect(page).toHaveURL(/\/platform\/login$/);
    const response = await page.goto('/admin/users');
    expect(response?.status()).toBe(403);
});

# Browser testing

Latest local run (2026-11-10): **78 passed in 3.6 minutes**, using headed Chromium and isolated SQLite fixtures. Browser testing found and fixed the recipient-attendance 500 caused by unsupported SQLite `least`/`greatest` functions, and nested buttons in the platform sign-out control. The final run had no detected JavaScript exceptions or HTTP 5xx responses in the checked pages and workflows.

The Playwright suite runs Chromium with a visible window against a local Laravel server on port 8015. It creates a new SQLite central database under `storage/app/playwright` and fresh tenant databases using generated tenant IDs. It does not use the application's configured database or existing accounts. Test email uses the array mailer and jobs run synchronously.

```powershell
npm.cmd install
npx.cmd playwright install chromium
npm.cmd run build
npm.cmd run test:browser
npm.cmd run test:browser:report
```

PHP and its SQLite extensions must be available on PATH. Port 8015 must be free. On systems without a desktop, set `PLAYWRIGHT_HEADLESS=1` before running the tests.

Coverage includes tenant and platform sign-in, platform password changes, tenant provisioning/editing/deletion, user creation/editing/password resets, lock/unlock, suspension/reactivation, ERP module page rendering, seeded project and requisition details, project creation/editing, supplier creation, opening inventory stock, equipment registration, budget adjustment, requisition publication, payroll preview, report previews, mobile dashboard rendering, and a restricted-role access check. Page rendering checks are smoke tests; they do not establish correctness of every financial calculation, approval branch, export, attachment, or role combination. SQLite testing does not replace PostgreSQL production validation.

The HTML report is written to `playwright-report/index.html`. Failed tests retain screenshots, videos, and traces under `test-results`. These artifacts can include test account details; generated artifacts and browser session state are ignored by Git. Fixture databases are retained locally for debugging. Every run uses fresh databases, so tests do not depend on previous runs.

To run a subset:

```powershell
npm.cmd run test:browser -- --grep "platform:"
npm.cmd run test:browser -- --grep "inventory:|budget:|requisition:"
```

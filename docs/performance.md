# Loading performance

The 2026-11-10 changes reduce work on every tenant request and shorten the initial render path:

- Validate the current home identity once, retain its tenant connection, and check only the selected shared company's active status and access grant. Selecting a different account still performs full verification. Locks, password changes, suspensions, and revoked grants are checked on subsequent requests.
- Resolve notification counts, current-project lookups, and navigation only when included in the Inertia response, avoiding that work on redirects and unrelated partial reloads.
- Reuse the finance-wallet balance already calculated for the dashboard.
- Remove the unused JavaScript route table from the application HTML. Frontend navigation already uses URL paths; server-side named routes remain available.
- Load the Poppins stylesheet without blocking rendering and use font swapping. Load dashboard charts after the dashboard cards can render, with fixed-height placeholders to avoid a layout jump.
- Calculate the HTML build stamp once per view while retaining deploy-version checks and session recovery.

Before/after measurements on the same local fixture shape:

| Page | Queries before | Queries after | Initial HTML before | Initial HTML after |
| --- | ---: | ---: | ---: | ---: |
| Login | — | — | 46,741 bytes | 4,639 bytes |
| Dashboard | 23 | 15 | 54,665 bytes | 12,563 bytes |
| Projects | 16 | 9 | 56,211 bytes | 14,109 bytes |
| Requisitions | 23 | 16 | 60,596 bytes | 18,494 bytes |

Query counts come from `RequestPerformanceTest` on an empty tenant with an administrator. HTML sizes come from Playwright with seeded demo data. These are uncompressed HTML sizes, excluding separately downloaded scripts and styles. Browser timings on the local PHP development server vary with cold startup, filesystem activity, and other tests; these reductions do not establish a production latency percentage.

An isolated follow-up browser run recorded these median times from navigation until the main page content was visible (three full loads per page):

| Page | Before | After |
| --- | ---: | ---: |
| Login | 783 ms | 444 ms |
| Dashboard | 737 ms | 668 ms |
| Projects | 1,266 ms | 986 ms |
| Requisitions | 729 ms | 634 ms |

These local samples are indicative, not production guarantees. The full 79-check browser suite passed, and the separate performance check confirmed both deferred charts render. Authentication, shared-company access, session recovery, and partial-reload regression checks were also exercised. Two stale finance-route expectations in the existing authenticated-page tests were aligned with the existing controller redirects.

## Verification

```powershell
php vendor/phpunit/phpunit/phpunit tests/Feature/RequestPerformanceTest.php
npm.cmd run test:browser
```

The browser performance check records three page loads each for login, dashboard, projects, and requisitions under `storage/app/playwright/performance-optimized.json`. It also checks that both deferred dashboard charts finish rendering. Set `PERFORMANCE_LABEL=baseline` before running the check against the previous implementation to save a separate baseline file. Compare timings with the same build mode, server configuration, fixture data, and machine load.

## Production deployment

Deploy the complete production build with the PHP changes. After environment values are configured for the release, cache Laravel's configuration, routes, events, and views:

```sh
npm ci
npm run build
php artisan optimize
php artisan queue:restart
```

For an atomic Forge deployment, ensure the server's PHP OPcache follows the new release (reload PHP-FPM as configured by the deployment). Configure compression and long-lived immutable caching for hashed `/build/assets/` files at the web server or CDN, and retain previous-release assets briefly for open tabs. These hosting settings require changes on the production host and were not applied locally. Keep authenticated HTML and Inertia responses private and uncached, as the application currently does.

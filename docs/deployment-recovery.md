# Deployments, sessions, and blank-page recovery

Application JavaScript/CSS must be served from the shared public `build` directory, not tenancy's domain-based `/tenancy/assets` route. This application identifies tenants through the session; tenant documents have separate authenticated delivery routes.

Deploy the complete output of `npm run build`, including `public/build/manifest.json` and all referenced assets, with the PHP changes. Publish releases atomically where possible and retain the previous release's hashed assets briefly for open tabs. Run `php artisan config:clear`, `php artisan route:clear`, and `php artisan view:clear` after replacing files, then regenerate production caches as part of the deployment. Restart long-lived workers after publishing the release.

Keep APP_KEY, session cookie settings, and the central session store stable between releases. Ordinary asset changes preserve valid sessions. Inertia's version now includes both the manifest content and the optional ASSET_VERSION; a changed build forces a full browser navigation even if ASSET_VERSION was left unchanged. Application HTML and Inertia responses are not cached.

Tenant identity restoration runs after sessions start and before authentication. Authentication-context version 2 validates the new verified-account format. Older or malformed tenant contexts are cleared and redirected to login once; platform authentication is preserved. Invalid account/password fingerprints also clear the tenant context. Read-only dashboard responses receive the same shared props as normal pages.

Stale company/account Inertia requests reload the dashboard; expired CSRF requests navigate to login. If frontend startup fails, server-rendered controls remain available to reload or reset the tenant sign-in session. Automatic retries are bounded per build. Session reset uses POST with CSRF and does not need to resolve the broken tenant identity.

For an already blank browser tab, perform one hard refresh after deploying the fixes. If needed, use the visible Reset sign-in session control. Never use a new application key or delete every user's sessions as a routine deployment step.

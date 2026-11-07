# Cross-company access

Platform administrators manage read-only company grants from Platform ? Tenant ? Users ? Read-only companies. Home-company roles determine read permissions; visitors cannot mutate records, export, print, or download attachments. A chooser appears after login when grants exist, and the application header offers company switching.

## Deployment

1. Run `php artisan migrate --force` for the central grant and audit tables.
2. Run `php artisan company-access:protect-attachments` to verify private copies and remove public attachment copies. The command is idempotent and stops on unrecognized or missing files. Grants are disabled until it succeeds. Existing public URLs stop working; attachment delivery uses authenticated routes.
3. Run `npm run build` and deploy the application assets.
4. Assign grants from platform administration. No existing user gains access automatically.

## Current boundaries

Visitors use an explicit viewing-route policy; unknown routes and edit forms fail closed. The visitor landing page avoids the unrestricted aggregate dashboard. Report previews require read permission to all aggregated modules. Personal notifications are suppressed. Target database queries reject non-SELECT statements while visiting. Revocations and home-role changes take effect on the next request.

Company visiting is disabled during impersonation. Session-local project context is cleared on company switching. Stale mutation requests are rejected by the company-context header. Application download restrictions cannot prevent screenshots or copying visible text.

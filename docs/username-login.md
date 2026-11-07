# Username login and reusable contact information

Tenant accounts sign in with a username unique within its company or a contact email, compared in lowercase after trimming. Email and phone are contact details and can be shared by accounts in the same or different companies. Accounts retain independent passwords, roles, and company grants. Company-access grants remain attached to central account IDs; account updates and deletion never select users by email.

New-user and edit-user forms support username and phone. Platform tenant provisioning also accepts an administrator username, and the CLI accepts `--admin-username`. Existing service integrations that omit username retain their email text as the username; to reuse that email, explicitly supply a different username. The legacy login payload field `email` remains accepted as an alias for the login identifier. Email login checks the supplied password against each matching active, unlocked account. Username login first verifies that exact account, then can unlock sibling accounts with the same email and matching password.

## Migration

Use a maintenance window: run central `php artisan migrate --force`, followed by `php artisan tenants:migrate --force`, deploy the code/assets, and restart long-lived workers. Do not serve logins between central and tenant migrations.

Existing accounts keep their email text as their initial lowercase username, with deterministic suffixes for case collisions. Password hashes, user IDs, central IDs, and grants remain unchanged. Each tenant's migration backfills the central identity-to-local-user mapping. Inform users about any suffixed usernames before reopening access. Platform administrator login remains email-based and separate.

The migrations intentionally refuse rollback because emails may have been reused since migration; reversing requires a dedicated forward data migration.

There is no active self-service password-reset route. Administrators can reset passwords from the chosen username account. The old unused email-keyed password-reset table must not be used for future recovery without adding username/account-ID identification.

## Multiple company accounts

A successful login keeps a server-side list of accounts whose passwords were verified, with a fingerprint of each password hash. If several accounts or read-only grants are available, the company chooser appears. Selecting a normal account loads that account's own identity, role, and permissions; selecting a read-only grant retains the grant owner's identity and viewing restrictions. Two accounts in the same tenant are distinct choices, labelled by username.

Shared email alone never authorizes another account. Accounts with different passwords require a separate login using that account's username or the shared email and its own password. Locked or suspended accounts are excluded. Password changes invalidate previously verified account access. Switching clears project context and regenerates the session. Browser requests carry both company and account context so stale forms cannot act under another account.

This login enhancement requires no additional database migrations beyond the username migrations above.

## Reusing usernames across companies

The same username can be created in different companies. The central database enforces uniqueness on `(tenant_id, username)` and each tenant still enforces local username uniqueness. Account updates, deletion, and grants use tenant/account IDs rather than username alone. A shared username/password can resolve several accounts and open the company chooser; different passwords only unlock the matching accounts. Apply central migration `2026_11_07_000002_scope_usernames_to_tenants` for this change. No tenant schema change is required.

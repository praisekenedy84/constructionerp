# CRF-ERP — Migration Plan: Laravel + Vite + Multi-Tenancy

**Status:** Planning document (target architecture, not implemented)  
**Assumes:** Current system as documented in `SYSTEM_DOCUMENTATION.md`  
**Goal:** Re-platform CRF-ERP onto **Laravel 11+** with **Vite** for the frontend, adding **true multi-tenancy** so multiple construction companies (tenants) can run isolated ERP instances from one deployment.

---

## 1. Executive summary

CRF-ERP today is a **monolithic modular** app: Go/Gin API + Next.js SPA, PostgreSQL, JWT RBAC, schema partitioned by `project_id` within a **single logical organization**. Multi-project is supported; multi-**tenant** is not.

This document describes how to rebuild (or incrementally migrate) the system as:

| Layer | Target |
|-------|--------|
| Backend | Laravel 11+, Eloquent, PostgreSQL |
| Frontend | Vite + **Inertia.js + Vue 3** (recommended) or Vite + React |
| Auth | Laravel Sanctum (SPA) or Passport (API-first); session or token per tenant |
| Multi-tenancy | **Database-per-tenant** (recommended) via `stancl/tenancy` |
| Queue / cron | Laravel Horizon + Scheduler |
| Storage | S3-compatible per tenant prefix or bucket |
| Money | `brick/money` or `decimal` columns + `cast` to string/BigDecimal pattern |

**What must not change:** The five financial-integrity rules in `PROJECT_RULES.md` / `SYSTEM_DOCUMENTATION.md` §2. The migration is a **stack and tenancy** change, not a redesign of BOQ, budget ledger, requisitions, or cash separation.

---

## 2. Why Laravel + Vite + multi-tenancy

### 2.1 Drivers for the change

| Driver | Notes |
|--------|-------|
| **SaaS / hosted ERP** | Sell to multiple contractors; each company is a tenant with own users, projects, audit trail |
| **Operational isolation** | Tenant A must never read Tenant B data — stronger than `project_id` scoping alone |
| **Per-tenant customization** | UI branding (already exists as Platform settings) → tenant-level settings, domains, feature flags |
| **Ecosystem** | Laravel: queues, mail, Excel/PDF (Maatwebsite, DomPDF), permissions (Spatie), auditing, tenancy packages |
| **Team skills** | PHP/Laravel hiring pool; Vite is framework-agnostic build tool |
| **Single deployable unit** | Inertia keeps one Laravel app serving UI + API; simpler ops than separate Next.js container |

### 2.2 What we are not changing

- Domain modules: projects, BOQ, budgets, requisitions, finance, procurement, inventory, payroll, equipment, valuations, reports, audit
- Append-only budget ledger, BOQ reservation engine, requisition state machine, cash-on-fulfillment rule
- Role-based approval thresholds (now **tenant-configurable**, not hardcoded)
- Report catalog intent (24+ reports); implementation swaps to Laravel exporters

### 2.3 What explicitly changes

| Current | Target |
|---------|--------|
| Go packages under `internal/{module}/` | Laravel modules under `app/Domain/{Module}/` or `modules/{Name}/` |
| Next.js App Router + TanStack Query | Inertia pages + optional Pinia/composables for client state |
| JWT refresh in `api.ts` | Sanctum cookie SPA auth or tenant-scoped API tokens |
| Single DB, global users | Central DB (tenants, domains, billing) + **tenant DBs** (all ERP data) |
| `Platform Admin` | Split into **Landlord Super Admin** (central) vs **Tenant Admin** (per company) |
| `SEED_DEMO=true` | Tenant provisioning command + demo seeder per tenant |

---

## 3. Multi-tenancy strategy

### 3.1 Options compared

| Strategy | Isolation | Ops complexity | Best for CRF-ERP |
|----------|-----------|----------------|------------------|
| **Single DB, `tenant_id` column** | Logical only | Low | Dev/staging; risky for financial ERP audit expectations |
| **Schema-per-tenant** (Postgres schemas) | Strong | Medium | Possible; backup/restore per tenant harder than separate DBs |
| **Database-per-tenant** | Strongest | Medium–high | **Recommended** — matches construction ERP compliance mindset |
| **Separate deployments per tenant** | Maximum | Very high | Enterprise only; not SaaS-efficient |

**Recommendation: database-per-tenant** using [`stancl/tenancy`](https://tenancyforlaravel.com/) v3.

**Rationale:**

- Financial and audit data benefit from physical separation (export, legal hold, offboarding = drop DB).
- Existing schema is already normalized; cloning migrations into tenant context is straightforward.
- `project_id` scoping **remains** inside each tenant DB — tenancy and project hierarchy are orthogonal:
  ```
  Landlord (central)     Tenant DB (Company A)          Tenant DB (Company B)
  ─────────────────      ─────────────────────          ─────────────────────
  tenants                users, roles, permissions      users, roles, ...
  domains                projects (project_id)          projects
  subscriptions          boq, requisitions, ...       boq, requisitions, ...
  landlord_users
  ```

### 3.2 Tenant identification

Resolve tenant **before** ERP routes run:

| Method | Example | Use |
|--------|---------|-----|
| Subdomain | `acme.crf-erp.com` | Primary SaaS pattern |
| Custom domain | `erp.acme.co.tz` | Enterprise tier |
| Path prefix | `/t/acme/...` | Fallback / dev only |

Central routes (landlord): `admin.crf-erp.com` — tenant CRUD, billing, impersonation into tenant.

### 3.3 Central (landlord) database

Minimal tables — **no project/finance data**:

```
tenants                 id, name, slug, plan, status, created_at
domains                 tenant_id, domain, is_primary
tenant_users            optional: cross-tenant support login audit
subscriptions           plan, limits (max_projects, max_users), renews_at
landlord_admins         platform operators (replaces global Platform Admin)
tenant_provision_jobs   async DB create/migrate/seed status
```

### 3.4 Tenant database

Run **full CRF-ERP migrations** in each tenant context. Every table from `SYSTEM_DOCUMENTATION.md` §5 lives here. No `tenant_id` column needed on ERP tables if using database-per-tenant.

**Exception:** If later adding cross-tenant analytics warehouse, ETL out — do not query tenant DBs from landlord for live UI.

### 3.5 Tenant lifecycle

```
1. Landlord creates tenant record
2. Queue job: CREATE DATABASE crf_{tenant_uuid}
3. Run tenant migrations
4. Seed: default roles, approval thresholds, optional demo project
5. Create Tenant Admin user + send invite
6. Mark tenant active
```

**Offboarding:** export audit + finance reports → soft-disable tenant → schedule DB archive/delete per retention policy.

---

## 4. Target architecture

### 4.1 High-level diagram

```
                    ┌─────────────────────────────────────┐
                    │           Nginx / Caddy             │
                    │  *.crf-erp.com  │  admin.crf-erp  │
                    └────────┬───────────────┬────────────┘
                             │               │
                    ┌────────▼───────────────▼────────────┐
                    │         Laravel Application          │
                    │  ┌─────────────┐ ┌───────────────┐  │
                    │  │  Landlord   │ │ Tenant (tenancy│  │
                    │  │  routes     │ │ middleware)   │  │
                    │  └─────────────┘ └───────────────┘  │
                    │  Domain Services │ Jobs │ Events    │
                    │  Inertia + Vite (Vue) │ Sanctum     │
                    └────────┬───────────────┬────────────┘
                             │               │
              ┌──────────────▼──┐    ┌───────▼──────────────┐
              │  Central PG DB   │    │  Tenant PG DBs       │
              │  tenants, domains│    │  crf_tenant_xxx ...  │
              └─────────────────┘    └──────────────────────┘
                             │
              ┌──────────────▼──┐
              │  Redis, S3, SMTP │
              └─────────────────┘
```

### 4.2 Recommended Laravel project structure

Monolithic modular — mirror Go module boundaries:

```
app/
├── Domain/
│   ├── Auth/           Models, Policies, Actions
│   ├── Projects/
│   ├── Boq/
│   ├── Budgets/
│   ├── Requisitions/   RequisitionTransitionService (single entry)
│   ├── Approvals/
│   ├── Finance/
│   ├── Procurement/
│   ├── Inventory/
│   ├── Payroll/
│   ├── Equipment/
│   ├── Valuations/
│   ├── Reports/
│   ├── Audit/
│   └── Notifications/
├── Http/
│   ├── Controllers/    Thin; delegate to Actions/Services
│   ├── Middleware/     TenantAware, EnsurePermission
│   └── Requests/       Form validation
├── Jobs/               Escalation, low stock, report schedules, provision tenant
├── Policies/           Spatie permission checks + model policies
└── Support/
    ├── AuditLogger.php
    └── MoneyCast.php

resources/js/
├── Pages/              Inertia pages (mirror current Next routes)
├── Components/         UI (reuse Tailwind + headless/shadcn-vue)
├── Composables/        usePermissions, useProject
└── app.ts              Vite entry

routes/
├── landlord.php        Central admin
├── tenant.php          All ERP routes (tenancy middleware)
└── web.php             Login redirect, health
```

**Rule (unchanged):** Controllers do not cross-mutate domain tables — e.g. `RequisitionTransitionAction` calls `BudgetService`, `BoqReservationService`, `FinanceCashService` in one DB transaction.

### 4.3 Frontend: Vite + Inertia (recommended)

| Approach | Pros | Cons |
|----------|------|------|
| **Inertia + Vue/React** | One auth/session model, no separate API contract, Laravel validation errors native | Not a public JSON API for mobile (add later) |
| **Vite SPA + REST API** | Closest to current Next.js | Duplicate auth, CORS, two deployment concerns |
| **Livewire + Vite** | Less JS | Poor fit for dense ERP tables/forms already built in React |

**Recommendation:** **Inertia + Vue 3 + TypeScript** + Tailwind. Port ShadCN patterns via [shadcn-vue](https://www.shadcn-vue.com/) or Radix-Vue.

**State:**

- Server props for page data (replaces most TanStack Query)
- Pinia only for: sidebar UI, impersonation banner, tenant branding cache
- Form state: Inertia `useForm`

**Route parity** (from `SYSTEM_DOCUMENTATION.md` §16):

| Current Next route | Inertia page |
|--------------------|--------------|
| `/dashboard` | `Dashboard/Index.vue` |
| `/projects/[id]/valuations` | `Projects/Valuations/Index.vue` |
| `/finance/expenses` | `Finance/Expenses/Index.vue` |
| `/admin/users` | Landlord: `Landlord/Tenants/...`; Tenant: `Admin/Users/Index.vue` |

---

## 5. Auth, roles & permissions in multi-tenant context

### 5.1 Two admin planes

| Plane | Who | Scope |
|-------|-----|-------|
| **Landlord** | Platform operators | Create/suspend tenants, billing, impersonate into tenant (audited) |
| **Tenant** | Company users | All ERP modules within one tenant DB |

Current **Platform Admin** maps to **Landlord Super Admin**.  
Current **System Administrator** maps to **Tenant Admin** (full permissions inside tenant).

### 5.2 Auth implementation

**Recommended:** Laravel Sanctum SPA authentication for Inertia (session + CSRF on tenant domain).

| Concern | Implementation |
|---------|----------------|
| Login | `POST /login` on tenant subdomain; credentials checked in **tenant DB** |
| Session | Encrypted cookie scoped to tenant domain |
| API tokens | Sanctum personal access tokens for integrations (optional) |
| Impersonation | Landlord issues short-lived signed URL → tenant session as target user; `audit_logs.impersonator_id` |
| Password reset | Tenant-scoped; mail config per tenant or shared SMTP with tenant branding |

### 5.3 RBAC

Use **[spatie/laravel-permission](https://spatie.be/docs/laravel-permission)** in **tenant context only**.

| Current (Go) | Laravel |
|--------------|---------|
| `permissions(role_id, module, action)` | `permissions` + `roles` with names like `budgets.approve` |
| Super-users bypass | `Gate::before` for Tenant Admin / Managing Director |
| 13 roles | Seed per tenant; **Manager** and **Platform Admin** split as above |
| `syncModulePermissions` on startup | Tenant seeder + migration hook for new permissions |
| Finance uses `budgets:*` | Keep same naming to ease migration docs, or rename to `finance.*` with alias middleware |

**Tenant-configurable approval thresholds:** Store `approval_workflow_configs` in tenant DB (unchanged model); landlord plan may cap max threshold levels.

---

## 6. Preserving business rules in Laravel

These implementations are **mandatory** regardless of stack.

### 6.1 Budget ledger

```php
// app/Domain/Budgets/BudgetLedger.php
final class BudgetLedger
{
    public function record(BudgetTransactionData $tx): void
    {
        // ONLY insert path — never update remaining on projects table
        BudgetTransaction::create([...]);
    }

    public function remaining(Project $project): Money
    {
        return $project->net_budget->minus(
            BudgetTransaction::where('project_id', $project->id)->sum('amount')
        );
    }
}
```

- Use `decimal(18,2)` or integer minor units; **never float**.
- Enum: `BudgetTransactionType` including `DIRECT_EXPENSE`.
- `MANUAL_ADJUSTMENT`: Form Request + Policy + required `reason`.

### 6.2 Requisition transition (single entry point)

```php
// app/Domain/Requisitions/RequisitionTransitionService.php
public function transition(Requisition $req, Status $to, User $actor, TransitionOptions $opts): void
{
    DB::transaction(function () use (...) {
        $this->assertValidTransition($req->status, $to);
        // BOQ check, approval steps, budget tx, reservation, cash on fulfill
        $this->history->record(...);
        $this->audit->log(...);
        $req->update(['status' => $to]);
    });
}
```

Port the Go `Transition()` logic test-for-test where possible.

### 6.3 Audit trail

Use **[spatie/laravel-activitylog](https://spatie.be/docs/laravel-activitylog)** or a custom `audit_logs` table matching current JSON snapshot shape for export compatibility.

- Immutable: no `update`/`delete` policies on `AuditLog`
- Actor: `$user->id`; if impersonating, store `impersonator_id`

### 6.4 Scheduled jobs

| Current cron | Laravel |
|--------------|---------|
| Approval escalation | `Schedule::job(EscalatePendingApprovals::class)->hourly()` **per tenant** — use `tenancy()->runForMultiple()` |
| Low stock | `CheckLowStock::class` |
| Report schedules | `RunDueReportSchedules::class` + queued mail |

**Critical:** Tenant jobs must bootstrap tenancy (`InitializeTenancyByDomain` or explicit `$tenant->run()`).

---

## 7. Module migration map

Go module → Laravel domain (1:1 intent):

| Go `internal/` | Laravel domain | Notes |
|----------------|----------------|-------|
| auth | Auth + Landlord | Split landlord vs tenant users |
| projects | Projects | + compliance rules delegate to Valuations |
| boq | Boq | CSV import → Maatwebsite Excel |
| budgets | Budgets | Ledger service |
| requisitions | Requisitions | Transition service is core |
| approvals | Approvals | Config-driven thresholds |
| finance | Finance | Cash approval workflow, expenses |
| procurement | Procurement | PO from requisition |
| inventory | Inventory | Weighted average default |
| payroll | Payroll | Posting → budget tx |
| equipment | Equipment | FUEL vs EQUIPMENT cost types |
| valuations | Valuations | IPC + compliance deductions |
| reports | Reports | Builders + export service |
| notifications | Notifications | Database notifications |
| audit | Audit | Central logger |
| demo | Database/Seeders | `TenantDemoSeeder` |
| middleware | Http/Middleware | Permission + tenancy |
| cron | Console/Schedule + Jobs | Tenant-aware |

### 7.1 Reports & exports

| Current | Laravel |
|---------|---------|
| Custom CSV/XLSX/PDF | `maatwebsite/excel`, `barryvdh/laravel-dompdf` or `spatie/laravel-pdf` |
| 24 report slugs | `ReportRegistry` class mirroring `document.go` catalog |
| Chart data endpoints | Optional: Inertia props + Chart.js, or JSON for partial reload |

### 7.2 File storage

| Current | Laravel |
|---------|---------|
| S3 uploads | `Storage::disk('s3')` with path `tenants/{tenant_id}/requisitions/...` **or** separate bucket per tenant on enterprise plan |

---

## 8. Data migration strategy

### 8.1 From current single-tenant Go app

If migrating existing production data (not greenfield):

1. Create one tenant in landlord DB representing the legacy company.
2. Provision empty tenant database.
3. ETL script (Go or PHP):
   - Export PostgreSQL tables in FK order from old DB.
   - Import into tenant DB (UUIDs preserved for audit continuity).
4. Map old `Platform Admin` → landlord user + tenant admin user.
5. Parallel run / cutover window with read-only on old system.

### 8.2 Schema conventions (Laravel)

| Topic | Convention |
|-------|------------|
| Primary keys | UUID (`HasUuids` trait) — match current IDs if migrating |
| Soft deletes | `SoftDeletes` on all business models |
| Timestamps | `created_at`, `updated_at`, `deleted_at` |
| Money | `decimal(18,2)` + custom cast |
| Enums | PHP 8.1 backed enums (`RequisitionStatus`, etc.) |

### 8.3 Indexes to add (tenant DB)

- `(project_id)` on all project-scoped tables (already implied)
- `(requisition_id, status)` on approval_steps
- `(project_id, type)` on budget_transactions
- `(user_id, read_at)` on notifications

---

## 9. API surface

### 9.1 Inertia-first (default)

Most current `/api/v1/*` routes become **web routes** returning Inertia responses. Internal JSON endpoints only where needed (exports, typeahead, chart partials).

### 9.2 Optional public API v1

For mobile or integrations later:

- Prefix: `https://{tenant}.crf-erp.com/api/v1/`
- Auth: Sanctum token with ability scopes (`budgets:read`, etc.)
- Same domain services as web — no duplicate business logic in controllers

---

## 10. Deployment & infrastructure

### 10.1 Docker Compose (dev)

```yaml
services:
  app:        # PHP-FPM + Laravel
  nginx:
  vite:       # npm run dev for HMR
  postgres:   # central DB
  # Tenant DBs: created dynamically; dev can use one extra postgres DB or same server
  redis:
  horizon:    # queue worker
  scheduler:  # php artisan schedule:work
```

### 10.2 Production

- **App:** Octane (FrankenPHP or Swoole) optional for concurrency
- **Tenant DBs:** Same Postgres cluster; connection pooling (PgBouncer); monitor DB count
- **Backups:** Per-tenant backup jobs; landlord metadata separately
- **Secrets:** `.env` landlord; tenant mail/S3 overrides in `tenants.data` JSON column if needed

### 10.3 Environment variables (landlord)

```
APP_URL=https://crf-erp.com
TENANCY_CENTRAL_CONNECTION=pgsql
TENANCY_DATABASE_PREFIX=crf_tenant_
SANCTUM_STATEFUL_DOMAINS=*.crf-erp.com
```

---

## 11. Phased migration roadmap

### Phase 0 — Foundation (2–3 weeks)

- [ ] Laravel app + Vite + Inertia + Vue + Tailwind
- [ ] `stancl/tenancy` wired: central migrations, tenant migrations, subdomain routing
- [ ] Landlord: create tenant, provision DB job
- [ ] Tenant: login, Sanctum, Spatie roles seed (13 roles adjusted for landlord/tenant split)
- [ ] Audit logger + money cast utilities
- [ ] CI: PHPUnit + Pest, tenant test trait (`TenancyTestCase`)

### Phase 1 — Core financial spine (4–6 weeks)

- [ ] Projects, BOQ (import), Budget ledger, Audit UI
- [ ] Port financial rule unit tests from Go (reservation, remaining budget)
- [ ] Tenant Admin + project create with compliance rules stub

### Phase 2 — Control loop (4–6 weeks)

- [ ] Requisitions + Transition service + approvals + notifications
- [ ] Finance: cash allocation (with Manager approval), reconciliation
- [ ] Procurement + GRN

### Phase 3 — Operations (3–4 weeks)

- [ ] Inventory, Payroll, Equipment
- [ ] Direct/indirect expenses + overhead

### Phase 4 — Revenue & reporting (3–4 weeks)

- [ ] Valuations / IPC module
- [ ] Report registry + exports + schedules
- [ ] Executive dashboard, forecast (physical progress field)

### Phase 5 — Landlord & migration (2–3 weeks)

- [ ] Landlord admin UI (tenants, plans, impersonation)
- [ ] ETL from legacy Go DB if needed
- [ ] Demo seeder per tenant (`DEMO_GUIDE` parity)
- [ ] Load testing on tenant provisioning + N tenant count

**Total estimate:** ~18–26 weeks for feature parity with current system, depending on team size and parallel UI porting.

---

## 12. UI porting notes (Next.js → Inertia/Vue)

| Next.js pattern | Inertia/Vue equivalent |
|-----------------|------------------------|
| `AppShell` + client auth redirect | Middleware `auth` + `HandleInertiaRequests` shared props (`auth.user`, `permissions`, `tenant.branding`) |
| TanStack Query | Server props + `router.reload({ only: [...] })` |
| Zustand auth store | Session + shared Inertia props |
| `api.request()` | `router.post()` / `useForm()` |
| ShadCN React | shadcn-vue / Radix-Vue components |
| `navigation.ts` permission filter | `NavItem` config in JS + `can()` helper from Spatie permissions |

**Improvement opportunity:** Server-side route protection replaces client-only `AppShell` gate (fixes gap noted in `SYSTEM_DOCUMENTATION.md` §8.6).

---

## 13. Multi-tenant product decisions (require stakeholder input)

| Decision | Options | Recommendation |
|----------|---------|----------------|
| Tenant billing | Stripe, manual invoice, none v1 | Manual v1; Stripe webhook in landlord |
| Plan limits | max users, max projects, modules enabled | JSON on `tenants.data` enforced in Policies |
| Cross-tenant users | One email in multiple tenants | Allow; separate user row per tenant DB |
| Shared suppliers/catalog | Central catalog pushed to tenants | Defer; keep suppliers tenant-local |
| Reporting across tenants | Landlord analytics | Read-only warehouse ETL; not live cross-DB |
| Data residency | Single region vs per-tenant region | Single region v1 |
| Custom domains | SSL per tenant | Caddy on-demand TLS or Cloudflare |

---

## 14. Risks & mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Financial logic regression | Critical | Port Go transition/ledger tests; parallel run during cutover |
| Tenant job without tenancy context | Data leak / wrong DB | Code review rule: all jobs accept `Tenant` or use middleware |
| DB proliferation | Ops overhead | Automate provision/deprovision; connection pooling; archive policy |
| Long migration timeline | Stakeholder fatigue | Phase 1 delivers tenant + BOQ + budget; demo-able early |
| Inertia lock-in for mobile API | Medium | Keep domain services framework-agnostic; add API layer in Phase 5 |
| Permission naming drift | Migration confusion | Document map `budgets:approve` → `budgets.approve` in seeders |

---

## 15. Package checklist (Laravel ecosystem)

| Purpose | Package |
|---------|---------|
| Multi-tenancy | `stancl/tenancy` |
| Permissions | `spatie/laravel-permission` |
| Auditing | Custom or `spatie/laravel-activitylog` |
| Excel | `maatwebsite/excel` |
| PDF | `barryvdh/laravel-dompdf` or `spatie/laravel-pdf` |
| Queue dashboard | `laravel/horizon` |
| Backups | `spatie/laravel-backup` (per-tenant scripts) |
| Money | `brick/money` |
| UUIDs | Laravel native `HasUuids` |
| Frontend | `@inertiajs/vue3`, `vite`, `tailwindcss`, optional `shadcn-vue` |

**Do not add** unless needed: Nova/Filament for main ERP UI (Inertia is already the product UI); separate Next.js app.

---

## 16. Success criteria

Migration is complete when:

1. **Tenancy:** Two tenant subdomains show isolated projects, users, and audit logs with zero cross-read.
2. **Financial integrity:** All Phase 6 exit checks in `PHASE_6_REPORTING.md` pass per tenant.
3. **Feature parity:** Modules listed in `SYSTEM_DOCUMENTATION.md` §4–§7 available in tenant UI.
4. **Landlord:** Provision, suspend, impersonate tenant with full audit.
5. **Performance:** Tenant provision < 2 min; dashboard P95 < 500ms on reference hardware.
6. **Docs:** Update `PROJECT_RULES.md` tech stack section; supersede Go/Next-specific guidance.

---

## 17. Relationship to existing documents

| Document | After migration |
|----------|-----------------|
| `PROJECT_RULES.md` | **Keep** §3 business rules; **replace** §2 tech stack with Laravel + Vite + tenancy |
| `PHASE_1`–`PHASE_6` | **Keep** as functional requirements; implementation checklists refer to Laravel domains |
| `SYSTEM_DOCUMENTATION.md` | **Archive** Go/Next specifics; replace with Laravel tenant architecture doc |
| `DEMO_GUIDE.md` | Update for `php artisan tenants:seed-demo {tenant}` |
| `README.md` | New quick start: Laravel Sail/Docker, subdomain hosts file |

---

## 18. Quick reference for AI assistants on the new stack

When implementing on Laravel + multi-tenancy:

1. **Always** confirm tenancy is initialized (`tenant()` helper) before any Eloquent query on ERP models.
2. **Never** put `projects`, `budget_transactions`, or `requisitions` in the central database.
3. **Business logic** lives in `app/Domain/*/Services` — not in controllers or Livewire.
4. **Budget changes** only through `BudgetLedger::record()`.
5. **Status changes** only through `RequisitionTransitionService::transition()`.
6. **Landlord routes** must not import tenant models without `$tenant->run(function () { ... })`.
7. **Tests** use `TenancyTestCase` with at least one provisioned tenant database.

---

*This document supersedes the Go/Next stack assumptions in `PROJECT_RULES.md` §2 only when the migration is approved and underway. Until then, the current codebase remains authoritative for production behavior.*

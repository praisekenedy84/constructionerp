# CRF-ERP — Construction Resource & Finance ERP

Laravel 12 · Inertia.js · React 18 + TypeScript · Tailwind · stancl/tenancy v3 · PostgreSQL

Multi-tenant construction ERP with budget ledger, BOQ reservations, requisition workflow, finance, procurement, inventory, payroll, equipment, valuations, and reporting.

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --force
npm install && npm run build

# Create tenant + admin
php artisan tenant:provision "Demo Construction" demo \
  --admin-email=admin@demo.local --admin-password=password

# Seed demo project, BOQ, suppliers
php artisan tenant:seed-demo demo

php artisan serve
```

Sign in: http://127.0.0.1:8000/login → `admin@demo.local` / `password`

## Architecture

| Layer | Stack |
|-------|-------|
| Backend | Laravel 12, Eloquent, BCMath for money |
| Frontend | Inertia + React + TypeScript + Tailwind |
| Tenancy | stancl/tenancy v3 — schema-per-tenant (PostgreSQL) or DB-per-tenant (SQLite dev) |
| Auth | Identity-based login — email resolves tenant via `central_users` |
| Permissions | spatie/laravel-permission — 13 roles seeded per tenant |

### Tenancy

- **Central DB:** `tenants`, `central_users`, `domains`, sessions
- **Tenant DB:** all ERP data (users, projects, BOQ, budgets, requisitions, etc.)
- No subdomain routing — `InitializeTenancyFromSession` middleware

### Financial rules (non-negotiable)

1. Budget derived from `budget_transactions` — never stored on projects
2. BOQ: `Available = budgeted − consumed − reserved`
3. Requisition status changes only via `RequisitionService::transition()`
4. Cash moves on disbursement, not approval
5. No hard deletes — audit everything (`LogsActivity` trait)
6. No float — `decimal` columns + BCMath

## Modules

| Module | Routes | Service |
|--------|--------|---------|
| Projects | `/projects` | — |
| BOQ | `/projects/{id}/boq` | `BOQService` |
| Budget | `/projects/{id}/budget` | `BudgetService` |
| Requisitions | `/requisitions` | `RequisitionService` |
| Approvals | `/approvals/steps` | `ApprovalService` |
| Finance | `/finance/{projectId}` | `CashAllocationService`, `ExpenseService` |
| Procurement | `/procurement/*` | `ProcurementService` |
| Inventory | `/inventory/*` | `InventoryService` |
| Payroll | `/payroll/*` | `PayrollService` |
| Equipment | `/equipment` | `EquipmentService` |
| Valuations | `/projects/{id}/valuations` | `ValuationService` |
| Reports | `/reports/*` | `ReportService` |
| Admin | `/admin/*` | Platform Admin / System Administrator |

## Commands

```bash
php artisan tenant:provision {name} {slug} [--admin-email=] [--admin-password=]
php artisan tenant:seed-demo {slug}
php artisan tenants:migrate --force
```

## Scheduled jobs (Forge: `* * * * * php artisan schedule:run`)

- `EscalateStaleApprovals` — hourly, per tenant
- `CheckLowStock` — daily, notifies Storekeeper
- `RunDueReportSchedules` — hourly

## Production (Laravel Forge)

```env
DB_CONNECTION=pgsql
TENANCY_USE_SCHEMAS=true
TENANCY_CENTRAL_CONNECTION=pgsql
```

Deploy script:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan tenants:migrate --force
npm ci && npm run build
php artisan optimize
php artisan queue:restart
```

## Testing

```bash
php artisan test
grep -r "forceDelete" app/   # must return zero results
```

## Build plan reference

See [BUILD_THIS_WEEK.md](./BUILD_THIS_WEEK.md) for the original day-by-day spec.

# CRF-ERP — Ship It This Week

**Stack:** Laravel 11 · Inertia.js · React 18 + TypeScript · Vite ·
Tailwind · ShadCN UI · stancl/tenancy v3 (schema-per-tenant) ·
PostgreSQL · spatie/laravel-permission · Laravel Forge deployment

**Base:** Fork/copy from the SIMS or NexStays repo. Strip the domain
logic, keep the scaffolding: tenancy config, Forge config, Inertia +
React + Vite wiring, auth patterns, AppShell layout.

**Client:** They are Tenant 1. The system is multi-tenant from day one —
Tenant 2 can be onboarded immediately after handover with zero code
changes.

**Deploy target:** New subdomain, new database, Laravel Forge.
The Forge site should exist and be reachable by end of Day 1.

---

## Non-negotiable financial rules
## (read these once, keep them in mind always)

**Rule 1 — Budget is derived, never stored:**
`Remaining = net_budget − SUM(budget_transactions.amount)`.
Never `UPDATE projects SET remaining = X`. Budget changes only by
inserting a `BudgetTransaction` row.

**Rule 2 — BOQ Reservation Engine:**
`Available = budgeted_qty − consumed_qty − reserved_qty`.
Approved → `reserved_qty ↑`. Fulfilled → `reserved ↓, consumed ↑`.
Rejected/cancelled → `reserved ↓`. Always in the same DB transaction
as the status change.

**Rule 3 — State machine is a branch:**
`Under Review → { Approved | Amended | Rejected }` — never a chain.
One method `RequisitionService::transition()` owns all status changes.

**Rule 4 — Cash moves on disbursement, not approval:**
Approval → BudgetTransaction only. Fulfilled (cash) →
`cash_allocations.utilized_amount` only. Never both at once.

**Rule 5 — No hard deletes. Every mutation → AuditLog row.**
SoftDeletes on all business models. AuditLog is immutable for all roles.

**Rule 6 — No float for money or quantity.**
Use `decimal` columns in migrations, `decimal` Eloquent casts.
`shopspring/decimal` equivalent = PHP's `BCMath` or store as integers.
In practice: `decimal(15,2)` for money, `decimal(15,4)` for quantities.

---

## Tenancy architecture (identity-based — users never see a tenant)

- Tenant = construction company. Users log in at the single domain.
  No tenant slug, no tenant code, no company selector at login.
- **Central schema (public):** `tenants`, `central_users`
  (email → tenant_id mapping, no passwords), `domains`.
- **Tenant schema:** everything else — users, projects, BOQ, budgets,
  requisitions, all of it.
- Tenant resolution happens in the **auth flow**, not the URL:
  1. Look up `central_users` by email → get tenant
  2. `tenancy()->initialize($tenant)`
  3. Verify password against tenant schema's `users` table
  4. Store `tenant_id` in session
  5. Every subsequent request: `InitializeTenancyFromSession` middleware
     re-initializes tenancy from `session('tenant_id')`
- Email must be globally unique across all tenants
  (unique index on `central_users.email`).
- Same generic error for "email not found" and "wrong password" —
  never distinguish between them (prevents tenant enumeration).
- No subdomain routing. No path routing. `stancl/tenancy`'s domain
  and path resolvers are disabled. Resolution is manual, inside auth.

### User creation is always a two-write atomic operation
When creating any user (tenant admin creating staff, platform admin
creating a tenant's first user):
```php
DB::transaction(function() use ($data, $tenant) {
    CentralUser::create(['email' => $data['email'],
                         'tenant_id' => $tenant->id]);
    // (tenancy already initialized, writes to tenant schema)
    User::create([...all user fields including hashed password...]);
});
```
If either write fails, neither persists.

---

## Roles (13 total — seed all on every new tenant)

Platform Admin · System Administrator · Managing Director · Manager ·
Finance Manager · Accountant · Quantity Surveyor · Procurement Officer ·
Storekeeper · Project Manager · Site Engineer · HR Officer · Auditor

**Super-users** (bypass permission checks, don't gate their routes):
Platform Admin · System Administrator · Managing Director

**Use `spatie/laravel-permission`** — tables live in tenant schema
automatically. Seed all 13 roles in the `TenantCreated` listener.

### Approval thresholds (seed in `workflow_configs` table)
| Amount (TZS) | Role |
|---|---|
| 0 – 500,000 | Project Manager |
| 500,001 – 5,000,000 | Finance Manager |
| 5,000,001+ | Managing Director |

---

## BudgetTransaction types (complete list)
`APPROVED_REQUISITION` · `AMENDED_REQUISITION` · `PURCHASE` ·
`PAYROLL` · `EQUIPMENT_COST` · `FUEL_COST` · `DIRECT_EXPENSE` ·
`MANUAL_ADJUSTMENT`

`MANUAL_ADJUSTMENT` requires non-empty `reason`, gated to Finance
Manager / Managing Director only.
`DIRECT_EXPENSE` is for expenses posted directly against the project
(not via requisition). Indirect/overhead expenses do NOT create a
BudgetTransaction — they are company overhead only.

---

## Database schema (all tenant migrations)

Run these in order. Every table needs `deleted_at` (SoftDeletes) except
`budget_transactions`, `requisition_status_histories`,
`inventory_transactions`, `audit_logs` — those are immutable append-only.

### Core
```
projects: id, code(unique), name, client, location,
  contract_amount(decimal 15,2), wht_percentage(decimal 5,2),
  net_budget(decimal 15,2), physical_progress_pct(decimal 5,2 default 0),
  start_date, end_date, status(planning/active/on_hold/closed),
  timestamps, deleted_at

withholding_tax_rates: id, project_id, rate_percent(decimal 5,2),
  effective_date, is_active(bool), timestamps

project_compliance_rules: id, project_id, rule_type(enum: retention/
  advance_recovery/wht/defect_liability/material_test/hiv_report),
  rate(decimal 5,2), is_active(bool), max_amount(decimal 15,2 nullable),
  timestamps
```

### BOQ
```
boq_sections: id, project_id, name, display_order, timestamps, deleted_at

boq_items: id, section_id, description, unit,
  category(materials/labor/equipment/fuel/transport/
    accommodation/subcontractors/administration/contingencies),
  budgeted_qty(decimal 15,4), unit_rate(decimal 15,2),
  budgeted_amount(decimal 15,2),
  reserved_qty(decimal 15,4 default 0),
  consumed_qty(decimal 15,4 default 0),
  requested_qty(decimal 15,4 default 0),
  approved_qty(decimal 15,4 default 0),
  procured_qty(decimal 15,4 default 0),
  received_qty(decimal 15,4 default 0),
  issued_qty(decimal 15,4 default 0),
  timestamps, deleted_at
  -- available_qty is an accessor: budgeted - consumed - reserved
  -- NEVER a stored column

boq_revisions: id, project_id, version_no(int), reason,
  requested_by(FK users), approved_by(FK users nullable),
  status(draft/active), activated_at(nullable), timestamps
```

### Budget
```
budget_transactions: id, project_id, boq_item_id(nullable),
  type(enum above), amount(decimal 15,2, always positive),
  reference_entity_type(nullable), reference_entity_id(nullable),
  reason(text nullable), created_by(FK users), created_at
  -- no updated_at, no deleted_at — immutable
```

### Requisitions
```
requisitions: id, requisition_no(unique), project_id, boq_item_id,
  department(string), requestor_id(FK users),
  status(draft/submitted/under_review/approved/amended/
         rejected/fulfilled/closed/cancelled),
  fulfillment_type(cash_disbursement/stock_issue/direct_supplier_payment),
  original_amount(decimal 15,2), amended_amount(decimal 15,2 nullable),
  timestamps, deleted_at

requisition_items: id, requisition_id, boq_item_id, description,
  quantity(decimal 15,4), unit_cost(decimal 15,2),
  line_total(decimal 15,2), timestamps

requisition_status_histories: id, requisition_id, from_status,
  to_status, actor_id(FK users), comment(text nullable),
  amendment_reason(text nullable), original_amount(decimal 15,2 nullable),
  amended_amount(decimal 15,2 nullable),
  variance(decimal 15,2 nullable), created_at
  -- no updated_at, no deleted_at

requisition_attachments: id, requisition_id, file_url,
  document_type(quotation/grn/receipt/invoice/other),
  uploaded_by(FK users), created_at
```

### Approvals
```
workflow_configs: id, project_id(nullable), level(int),
  role_name(string), threshold_min(decimal 15,2),
  threshold_max(decimal 15,2 nullable), escalation_hours(int default 48),
  timestamps

approval_steps: id, requisition_id, level(int), required_role(string),
  status(pending/approved/rejected/skipped),
  assigned_at, resolved_at(nullable), timestamps

approval_actions: id, approval_step_id, actor_id(FK users),
  action(string), comment(text nullable), created_at
```

### Finance
```
cash_allocations: id, project_id, requested_amount(decimal 15,2),
  received_amount(decimal 15,2 default 0),
  utilized_amount(decimal 15,2 default 0),
  status(pending/approved/rejected/received),
  requested_by(FK users), approved_by(FK users nullable),
  method(string nullable), reference_no(string nullable),
  requested_at, received_at(nullable), timestamps
  -- balance = received_amount - utilized_amount (accessor, not column)

cash_disbursements: id, requisition_id, cash_allocation_id,
  amount(decimal 15,2), method(string), payee(string nullable),
  disbursed_by(FK users), disbursed_at, created_at

expenses: id, project_id(nullable), boq_item_id(nullable),
  category(direct/indirect), sub_type(string),
  activity_ref(string nullable), asset_reg_no(string nullable),
  amount(decimal 15,2), description(text nullable),
  expense_date(date), recorded_by(FK users), timestamps, deleted_at
  -- direct expenses: project_id required, creates DIRECT_EXPENSE
  -- indirect expenses: project_id null, overhead only, no BudgetTransaction
```

### Procurement
```
suppliers: id, name, contact_info(text), performance_rating(decimal 3,2 nullable),
  timestamps, deleted_at

quotations: id, requisition_id, supplier_id, amount(decimal 15,2),
  valid_until(date), submitted_at, timestamps

purchase_orders: id, requisition_id, supplier_id, boq_item_id,
  quantity(decimal 15,4), unit_cost(decimal 15,2),
  total_amount(decimal 15,2),
  status(draft/sent/confirmed/partially_received/fully_received/cancelled),
  timestamps, deleted_at

goods_receipts: id, purchase_order_id, quantity_received(decimal 15,4),
  condition(good/damaged/partial), received_by(FK users),
  received_at, created_at
```

### Inventory
```
inventory_items: id, code(unique), name, unit,
  category(materials/tools/fuel/consumables/spare_parts),
  reorder_point(decimal 15,4 nullable), timestamps, deleted_at

stock_locations: id, project_id, name, timestamps, deleted_at

stock_balances: id, inventory_item_id, stock_location_id,
  quantity_on_hand(decimal 15,4 default 0),
  average_cost(decimal 15,2 default 0), updated_at
  -- unique(inventory_item_id, stock_location_id)

inventory_transactions: id, inventory_item_id, stock_location_id,
  type(IN/OUT/TRANSFER/RETURN/ADJUSTMENT/DAMAGE),
  quantity(decimal 15,4), unit_cost(decimal 15,2 nullable),
  reference_entity_type(nullable), reference_entity_id(nullable),
  created_by(FK users), created_at
  -- no updated_at, no deleted_at

inventory_issues: id, requisition_id, inventory_item_id,
  stock_location_id, quantity(decimal 15,4), recipient_id(FK users),
  work_section(nullable), value(decimal 15,2), issued_at, created_at
```

### Payroll
```
employees: id, employee_no(unique), name, role(string),
  pay_structure(daily/monthly), daily_rate(decimal 10,2 nullable),
  monthly_salary(decimal 12,2 nullable), project_id(FK),
  timestamps, deleted_at

attendances: id, employee_id, date, status(present/absent/half_day/leave),
  hours_worked(decimal 5,2 nullable), created_at

payroll_runs: id, project_id, period_start(date), period_end(date),
  status(draft/approved/posted), timestamps

payroll_items: id, payroll_run_id, employee_id,
  boq_item_id(nullable), base(decimal 12,2), overtime(decimal 12,2 default 0),
  allowances(decimal 12,2 default 0), deductions_total(decimal 12,2 default 0),
  net_pay(decimal 12,2), created_at

payroll_deductions: id, payroll_item_id,
  type(NSSF/WCF/SDL/advance_recovery/other),
  amount(decimal 10,2), created_at

advances: id, employee_id, project_id, amount(decimal 10,2),
  issued_at, recovered_at(nullable), payroll_item_id(nullable), timestamps
```

### Equipment
```
equipment: id, name, type(string), status(available/assigned/
  under_maintenance/retired), timestamps, deleted_at

equipment_assignments: id, equipment_id, project_id,
  boq_item_id(nullable), hours_budgeted(decimal 8,2 nullable),
  hours_used(decimal 8,2 default 0), start_date,
  end_date(nullable), timestamps

equipment_maintenances: id, equipment_id, type(maintenance/repair),
  cost(decimal 10,2), description(text nullable), date, created_at

equipment_fuel_logs: id, equipment_id, assignment_id(nullable),
  liters(decimal 8,2), cost(decimal 10,2), date, created_at
```

### Valuations
```
valuations: id, project_id, certificate_no(auto-increment per project),
  gross_value(decimal 15,2), total_deductions(decimal 15,2),
  net_value(decimal 15,2), status(draft/certified),
  created_by(FK users), certified_by(FK users nullable),
  certified_at(nullable), timestamps, deleted_at

valuation_deductions: id, valuation_id, rule_type(string),
  rate(decimal 5,2), amount(decimal 15,2), created_at
```

### System
```
notifications: id, user_id, type, data(json),
  read_at(nullable), created_at

audit_logs: id, entity_type, entity_id, action,
  before_data(jsonb nullable), after_data(jsonb nullable),
  performed_by(FK users nullable), ip_address(nullable),
  user_agent(nullable), created_at
  -- no updated_at, no deleted_at, no SoftDeletes
  -- immutable for all roles including Platform Admin

system_settings: id, key(unique), value(json), updated_at
  -- stores ui_settings: app name, tagline, nav overrides
```

---

## Service layer (one class per domain)

```
app/Services/
  AuthService.php          — identity-based login, token management
  BOQService.php           — import, revision, activate
  BudgetService.php        — remainingBudget(), createTransaction()
  RequisitionService.php   — transition() ← ONLY status-change path
  ApprovalService.php      — resolve steps, trigger escalation
  CashAllocationService.php — suggest, receive, reconciliation
  FulfillmentService.php   — routes Fulfilled to cash OR stock path
  ProcurementService.php   — PO from requisition, GRN, variance check
  InventoryService.php     — issue, transfer, return, damage, adjust
  PayrollService.php       — generate, post (immutable after post)
  EquipmentService.php     — maintenance/fuel → BudgetTransaction
  ValuationService.php     — create, apply deductions in order, certify
  ExpenseService.php       — direct (→ BudgetTransaction) vs indirect
  ReportService.php        — all report builders
  AuditService.php         — write AuditLog (called via trait/event)
```

Controllers are thin: validate via Form Request → call service →
return `Inertia::render()` or JSON. No business logic in controllers.

---

## RequisitionService::transition() — implement this first, correctly

```php
public function transition(
    Requisition $req,
    string $toStatus,
    User $actor,
    array $opts = []
): Requisition {
    $allowed = [
        'draft'        => ['submitted'],
        'submitted'    => ['under_review'],
        'under_review' => ['approved', 'amended', 'rejected'],
        'approved'     => ['fulfilled', 'cancelled'],
        'amended'      => ['fulfilled', 'cancelled'],
        'fulfilled'    => ['closed'],
        'rejected'     => [],
        'cancelled'    => [],
        'closed'       => [],
    ];

    if (!in_array($toStatus, $allowed[$req->status] ?? [])) {
        throw new InvalidTransitionException($req->status, $toStatus);
    }

    DB::transaction(function() use ($req, $toStatus, $actor, $opts) {

        // 1. Write history row first (always)
        RequisitionStatusHistory::create([
            'requisition_id'   => $req->id,
            'from_status'      => $req->status,
            'to_status'        => $toStatus,
            'actor_id'         => $actor->id,
            'comment'          => $opts['comment'] ?? null,
            'amendment_reason' => $opts['amendment_reason'] ?? null,
            'original_amount'  => $req->original_amount,
            'amended_amount'   => $opts['amended_amount'] ?? null,
            'variance'         => isset($opts['amended_amount'])
                ? bcsub((string)$req->original_amount,
                        (string)$opts['amended_amount'], 2)
                : null,
        ]);

        // 2. BOQ + Budget side effects
        match ($toStatus) {
            'approved' => $this->onApproved($req, $actor, $opts),
            'amended'  => $this->onAmended($req, $actor, $opts),
            'rejected' => null, // no BOQ or budget effect
            'fulfilled'=> $this->onFulfilled($req, $actor, $opts),
            'cancelled'=> $this->onCancelled($req, $actor),
            'closed'   => $this->onClosed($req),
            default    => null,
        };

        // 3. Update status
        $req->update(['status' => $toStatus]);

        // 4. Notify relevant parties
        $this->notify($req, $toStatus, $actor);
    });

    return $req->fresh();
}
```

### onApproved
```php
private function onApproved(Requisition $req, User $actor, array $opts): void
{
    $boqItem = BOQItem::lockForUpdate()->findOrFail($req->boq_item_id);
    $qty = $req->requisitionItems->sum('quantity');

    if ($qty > $boqItem->available_qty) {
        // available_qty accessor: budgeted - consumed - reserved
        if (!($opts['override'] ?? false) ||
            !$actor->hasRole(['Finance Manager', 'Managing Director'])) {
            throw new BOQLimitExceededException($boqItem, $qty);
        }
        // Override: logged via history row comment above
    }

    $boqItem->increment('reserved_qty', $qty);
    $boqItem->increment('approved_qty', $qty);

    BudgetService::createTransaction($req->project_id, [
        'type'                  => 'APPROVED_REQUISITION',
        'amount'                => $req->original_amount,
        'boq_item_id'           => $req->boq_item_id,
        'reference_entity_type' => 'requisition',
        'reference_entity_id'   => $req->id,
        'created_by'            => $actor->id,
    ]);
}
```

### onAmended
```php
private function onAmended(Requisition $req, User $actor, array $opts): void
{
    if (empty($opts['amended_amount']) || empty($opts['amendment_reason'])) {
        throw new \InvalidArgumentException(
            'amended_amount and amendment_reason are required'
        );
    }

    $amendedQty = $req->requisitionItems->sum('quantity')
        * ($opts['amended_amount'] / $req->original_amount);

    $boqItem = BOQItem::lockForUpdate()->findOrFail($req->boq_item_id);
    if ($amendedQty > $boqItem->available_qty) {
        if (!($opts['override'] ?? false) ||
            !$actor->hasRole(['Finance Manager', 'Managing Director'])) {
            throw new BOQLimitExceededException($boqItem, $amendedQty);
        }
    }

    $boqItem->increment('reserved_qty', $amendedQty);

    $req->update(['amended_amount' => $opts['amended_amount']]);
    // original_amount is never touched

    BudgetService::createTransaction($req->project_id, [
        'type'                  => 'AMENDED_REQUISITION',
        'amount'                => $opts['amended_amount'], // amended only
        'boq_item_id'           => $req->boq_item_id,
        'reference_entity_type' => 'requisition',
        'reference_entity_id'   => $req->id,
        'created_by'            => $actor->id,
    ]);
}
```

### onFulfilled
```php
private function onFulfilled(Requisition $req, User $actor, array $opts): void
{
    $qty = $req->requisitionItems->sum('quantity');
    $amount = $req->amended_amount ?? $req->original_amount;

    // Convert reservation → consumption on BOQ item
    BOQItem::lockForUpdate()->findOrFail($req->boq_item_id)
        ->decrement('reserved_qty', $qty);
    BOQItem::find($req->boq_item_id)->increment('consumed_qty', $qty);

    // Route to cash or stock fulfillment — NO new BudgetTransaction
    match ($req->fulfillment_type) {
        'cash_disbursement', 'direct_supplier_payment'
            => app(FulfillmentService::class)->fulfillCash($req, $actor, $amount),
        'stock_issue'
            => app(FulfillmentService::class)->fulfillStock($req, $actor),
    };
}
```

### onCancelled
```php
private function onCancelled(Requisition $req, User $actor): void
{
    $qty = $req->requisitionItems->sum('quantity');
    $amount = $req->amended_amount ?? $req->original_amount;
    $type = $req->amended_amount
        ? 'AMENDED_REQUISITION' : 'APPROVED_REQUISITION';

    // Release BOQ reservation
    BOQItem::lockForUpdate()->findOrFail($req->boq_item_id)
        ->decrement('reserved_qty', $qty);

    // Append-only reversal — never delete original transaction
    BudgetService::createTransaction($req->project_id, [
        'type'                  => $type,
        'amount'                => -$amount, // signed negative
        'reference_entity_type' => 'requisition_cancellation',
        'reference_entity_id'   => $req->id,
        'reason'                => 'Cancellation reversal',
        'created_by'            => $actor->id,
    ]);
}
```

### onClosed
```php
private function onClosed(Requisition $req): void
{
    if ($req->attachments()->count() === 0) {
        throw new ClosingRequiresDocumentException($req->id);
    }
}
```

---

## AuditLog trait — wire once, apply everywhere

```php
// app/Traits/LogsActivity.php
trait LogsActivity
{
    protected static function bootLogsActivity(): void
    {
        static::created(fn($m)  => static::writeAudit($m, 'created', null));
        static::updated(fn($m)  => static::writeAudit($m, 'updated',
            $m->getOriginal()));
        static::deleted(fn($m)  => static::writeAudit($m, 'deleted',
            $m->getOriginal()));
    }

    private static function writeAudit($model, string $action,
        ?array $before): void
    {
        AuditLog::create([
            'entity_type'  => class_basename($model),
            'entity_id'    => $model->getKey(),
            'action'       => $action,
            'before_data'  => $before,
            'after_data'   => $model->toArray(),
            'performed_by' => auth()->id(),
            'ip_address'   => request()->ip(),
            'user_agent'   => request()->userAgent(),
        ]);
    }
}
```

Apply `use LogsActivity` to every model except `AuditLog`,
`BudgetTransaction`, `RequisitionStatusHistory`,
`InventoryTransaction` — those are append-only, not updated.

---

## Day-by-day build plan

### DAY 1 — Scaffold, tenancy, auth, deploy

**Morning**
- [ ] Copy SIMS/NexStays base into new repo. Remove all SIMS domain
      models, migrations, pages, services. Keep: tenancy config,
      Forge config, Inertia + React + Vite setup, AppShell layout
      component, auth scaffolding structure.
- [ ] `composer require stancl/tenancy spatie/laravel-permission
      maatwebsite/excel barryvdh/laravel-dompdf`
- [ ] `npm install` — confirm Vite + React + Tailwind + ShadCN builds.
- [ ] Configure `stancl/tenancy` for schema-per-tenant on PostgreSQL.
      Disable domain and path resolvers. Set up central migrations:
      `tenants`, `central_users` (email unique, tenant_id FK), `domains`.
- [ ] Write `InitializeTenancyFromSession` middleware. Register after
      `auth` in the web middleware group.
- [ ] Write `AuthService::login()` per the login flow spec above.
      Wire login controller + Login.tsx page (email + password only,
      no tenant field).
- [ ] Seed 13 roles + workflow_configs in `TenantCreated` listener.
- [ ] Write `LogsActivity` trait and `AuditLog` model now — apply to
      every model as you create it throughout the week.

**Afternoon**
- [ ] Create the client's tenant via `php artisan tinker` or a seeder:
      `Tenant::create(['name' => 'ClientName', 'slug' => 'client-slug'])`
      Run tenant migrations. Create Platform Admin + client's System
      Administrator user (two-write atomic pattern).
- [ ] Confirm login works: email → central lookup → tenant init →
      password check → session → dashboard.
- [ ] **Deploy to Forge now.** Don't wait until Day 5. New site, new
      database (already provisioned), subdomain pointed. Get the
      login page live before end of Day 1. This makes every subsequent
      day's work immediately visible on the real URL.
- [ ] Set up Forge deploy script: `composer install --no-dev`,
      `php artisan migrate --force`, `npm run build`,
      `php artisan queue:restart`, `php artisan optimize`.

**Day 1 exit check:**
- Login works on the live subdomain with the client's credentials.
- Logging in as two users from different tenants shows isolated data.
- Forge auto-deploy triggers on `git push`.

---

### DAY 2 — Projects, BOQ, Budget ledger

- [ ] All migrations for: `projects`, `withholding_tax_rates`,
      `project_compliance_rules`, `boq_sections`, `boq_items`,
      `boq_revisions`, `budget_transactions`, `workflow_configs`.
- [ ] `Project` model: `net_budget` computed on create
      (`contract_amount * (1 - wht_percentage/100)`), never user input.
      `available_qty` accessor on `BOQItem`.
- [ ] `BudgetService::remainingBudget(Project $p)`:
      `$p->net_budget - BudgetTransaction::where('project_id',$p->id)->sum('amount')`.
- [ ] `BudgetService::createTransaction(int $projectId, array $data)`:
      the single write path for all budget ledger entries.
- [ ] BOQ import via `maatwebsite/excel`: accept CSV/Excel, map to
      `BOQItem` fields, return per-row error report.
- [ ] **Pages:**
  - `Projects/Index.tsx` — project list, create button
  - `Projects/Create.tsx` — contract amount, WHT%, compliance rules
    (6 deduction types configurable at project create)
  - `Projects/Show.tsx` — net budget prominent, tabs: BOQ / Budget /
    Requisitions / Finance / Reports
  - `BOQ/Index.tsx` — section → item tree, expandable, shows
    Budgeted / Reserved / Consumed / Available per item
  - `BOQ/Import.tsx` — file upload, column map, per-row error display
  - `Budgets/Show.tsx` — remaining budget, transaction ledger list
- [ ] Share `currentProject` in `HandleInertiaRequests::share()`
      so every page has it.

**Day 2 exit check:**
- Create a project → net_budget computed correctly server-side.
- Import a BOQ CSV → items appear in tree with correct available_qty.
- Manual budget adjustment → remaining changes, BudgetTransaction
  row created, AuditLog row created.

---

### DAY 3 — Requisitions, Approvals, Reservation Engine

- [ ] All migrations for: `requisitions`, `requisition_items`,
      `requisition_status_histories`, `requisition_attachments`,
      `approval_steps`, `approval_actions`.
- [ ] `RequisitionService::transition()` — implement exactly as
      specified in this document. This is the most important method
      in the codebase. Test it thoroughly before building the UI.
- [ ] `ApprovalService::resolve()` — creates ApprovalSteps on
      submission based on `workflow_configs`, resolves them, calls
      `transition()` when all required steps are done.
- [ ] Auto-escalation: `php artisan schedule:run` via Forge scheduler.
      `EscalateStaleApprovals` job checks `approval_steps` where
      `status = pending` and `assigned_at < now - escalation_hours`.
- [ ] Requisition number generation: `REQ-{YEAR}-{5-digit-sequence}`
      unique per tenant, auto-generated in `RequisitionService::create()`.
- [ ] File attachments: store via Laravel filesystem (S3 or local),
      return URL. Required before `closed` transition.
- [ ] **Pages:**
  - `Requisitions/Index.tsx` — full book, searchable, filterable by
    status / department / category / date
  - `Requisitions/Create.tsx` — BOQ item selector showing live
    available_qty, quantity, unit cost, fulfillment type
  - `Requisitions/Show.tsx` — full timeline: history, original vs
    amended vs variance, attachments, BOQ impact panel
  - `Requisitions/Review.tsx` — Finance review queue; inline
    Approve / Amend (requires amount + reason) / Reject panels
- [ ] Notifications: database channel, in-app bell with unread count
      in `HandleInertiaRequests::share()`.

**Day 3 exit check:**
- Full requisition lifecycle: Draft → Submitted → Under Review →
  Approved → Fulfilled → Closed. Verify after each transition:
  `reserved_qty`, `consumed_qty`, `BudgetTransaction` rows,
  `RequisitionStatusHistory` rows, `AuditLog` rows.
- Approving beyond Available is blocked (or overridden with log).
- Cancelling an approved requisition releases reservation and creates
  a negating BudgetTransaction.
- Closing without attachment is blocked.

---

### DAY 4 — Finance, Procurement, Inventory

**Finance / Cash**
- [ ] Migrations: `cash_allocations`, `cash_disbursements`, `expenses`.
- [ ] `CashAllocationService`: 3-step flow (request → Manager approves
      → Finance marks received). `balance` accessor only, never column.
- [ ] `FulfillmentService::fulfillCash()`: check balance ≥ amount
      before proceeding; increment `utilized_amount`; create
      `CashDisbursement` row; no new BudgetTransaction.
- [ ] `ExpenseService`: direct (project_id required, creates
      `DIRECT_EXPENSE` BudgetTransaction) vs indirect (no project,
      no BudgetTransaction, overhead only).
- [ ] Reconciliation: Committed / Disbursed / Outstanding / Cash on Hand
      — four numbers, each independently queryable.
- [ ] **Pages:** `Finance/Index.tsx` (dashboard), `Finance/CashFlow.tsx`,
      `Finance/Reconciliation.tsx`, `Finance/Expenses.tsx`,
      `Finance/Overhead.tsx`.

**Procurement**
- [ ] Migrations: `suppliers`, `quotations`, `purchase_orders`,
      `goods_receipts`.
- [ ] `ProcurementService::createPOFromRequisition()` — pre-fills from
      approved requisition, surfaces price variance if supplier cost
      ≠ BOQ unit_rate.
- [ ] GRN → updates `BOQItem.received_qty`, fires inventory IN
      transaction via `InventoryService`.
- [ ] **Pages:** `Procurement/Index.tsx`, supplier CRUD, PO flow, GRN.

**Inventory**
- [ ] Migrations: `inventory_items`, `stock_locations`,
      `stock_balances`, `inventory_transactions`, `inventory_issues`.
- [ ] `InventoryService`: issue (against requisition), transfer (paired
      OUT+IN), return, damage (separate from issue in all reports),
      adjust. All inside DB transactions. Stock cannot go negative.
- [ ] `FulfillmentService::fulfillStock()`: calls `InventoryService::issue()`,
      converts BOQ reservation → consumption. No BudgetTransaction.
- [ ] Low-stock scheduled job → notification to Storekeeper.
- [ ] **Pages:** `Inventory/Stock.tsx`, `Inventory/Issues.tsx`,
      `Inventory/Transactions.tsx`.

**Day 4 exit check:**
- Cash fulfillment: `utilized_amount` increases, balance decreases,
  no duplicate BudgetTransaction.
- Stock fulfillment: `quantity_on_hand` decreases, BOQ
  `reserved→consumed`, no BudgetTransaction.
- Direct expense: BudgetTransaction of type `DIRECT_EXPENSE` created,
  remaining budget decreases.
- Indirect expense: no BudgetTransaction, overhead only.
- Reconciliation: Outstanding = Committed − Disbursed exactly.

---

### DAY 5 — Payroll, Equipment, Valuations, Reports, Final deploy

**Payroll**
- [ ] Migrations: `employees`, `attendances`, `payroll_runs`,
      `payroll_items`, `payroll_deductions`, `advances`.
- [ ] `PayrollService::generate()` — pure calculation from attendance,
      returns items before DB write. `post()` — creates `PAYROLL`
      BudgetTransaction per item, marks run immutable.
- [ ] **Pages:** `Payroll/Index.tsx`, `Payroll/Attendance.tsx` (bulk
      grid entry), `Payroll/Generate.tsx` (preview before post with
      irreversibility warning).

**Equipment**
- [ ] Migrations: `equipment`, `equipment_assignments`,
      `equipment_maintenances`, `equipment_fuel_logs`.
- [ ] Maintenance logged → `EQUIPMENT_COST` BudgetTransaction.
      Fuel logged → `FUEL_COST` BudgetTransaction. Separate tables,
      separate transaction types — never merged.
- [ ] **Pages:** `Equipment/Index.tsx`, assignments, maintenance log,
      fuel log.

**Valuations / IPC**
- [ ] Migrations: `valuations`, `valuation_deductions`.
- [ ] `ValuationService::create()` — takes gross value, reads project's
      `project_compliance_rules`, applies deductions in fixed order:
      retention → advance recovery → WHT → defect liability →
      material test → HIV report. Advance recovery respects
      `max_recovery_amount` vs cumulative prior recoveries.
- [ ] `certify()` — draft → certified, records certifier + timestamp.
- [ ] Valuation certificate PDF via `barryvdh/laravel-dompdf`.
- [ ] **Pages:** `Projects/[id]/Valuations/Index.tsx`,
      `Valuations/Create.tsx`, certificate view + download.

**Reports**
- [ ] `ReportService` — all builders in one service, data returned
      as arrays for Inertia props.
- [ ] Build in priority order (client will look at these first):
  1. Executive dashboard (projects summary, budget utilization,
     cash position, pending approvals count)
  2. Project profitability (contract vs cost vs margin)
  3. Budget utilization by cost center (BOQ category rollup)
  4. Cash position + cash flow statement
  5. BOQ dashboard (utilization % per category, variance, forecast)
  6. Requisition pipeline (by status, by department)
  7. Inventory valuation
  8. Payroll summary
  9. Equipment utilization
  10. Valuation certificate (PDF)
  11. Audit trail
- [ ] Shared export: CSV / XLSX (`maatwebsite/excel`) / PDF
      (`barryvdh/laravel-dompdf`). One export utility, all reports
      use it.
- [ ] Report schedules: `report_schedules` table, cron job, email
      via SMTP (log if SMTP not configured).
- [ ] **Pages:** `Reports/Index.tsx` (catalog), `Reports/[slug].tsx`
      (individual report with filters + export buttons),
      `Reports/Schedules.tsx`.

**Platform Admin**
- [ ] `/admin/users` — user CRUD for Platform Admin.
- [ ] `/admin/settings` — UI branding: app name, tagline, nav overrides
      stored in `system_settings` key `ui_settings`. Share via
      `HandleInertiaRequests`.
- [ ] Impersonation: `POST /auth/impersonate/:userId` — Platform Admin
      only, cannot impersonate other Platform Admins or self.
      AuditLog records impersonator as actor.

**Final deploy checklist**
- [ ] `php artisan migrate --force` on production.
- [ ] `npm run build` — confirm no TypeScript errors.
- [ ] `php artisan optimize` + queue worker running via Forge.
- [ ] Forge scheduler active (`* * * * * php artisan schedule:run`).
- [ ] S3 credentials set for attachment storage (or local disk
      confirmed working).
- [ ] SMTP configured for report email delivery (or confirmed
      logging acceptable for handover).
- [ ] Create client's tenant admin account. Walk through login.
      Confirm tenant isolation: create a second test tenant, confirm
      data is completely invisible between the two.
- [ ] Regression check:
  - Net Budget − Remaining = SUM(budget_transactions) ✓
  - Outstanding Commitment = Committed − Disbursed ✓
  - BOQ Available correct after full requisition lifecycle ✓
  - Every action appears in audit_logs ✓
  - `grep -r "forceDelete" app/` returns zero results ✓

---

## API routes reference

```
POST   /login                          AuthController@login
POST   /logout                         AuthController@logout
GET    /me                             AuthController@me

GET    /projects                       ProjectController@index
POST   /projects                       ProjectController@store
GET    /projects/{id}                  ProjectController@show
PATCH  /projects/{id}/progress         ProjectController@updateProgress

GET    /projects/{id}/boq              BOQController@tree
POST   /projects/{id}/boq/import       BOQController@import
POST   /boq/revisions                  BOQRevisionController@store
POST   /boq/revisions/{id}/activate    BOQRevisionController@activate

GET    /projects/{id}/budget           BudgetController@show
POST   /projects/{id}/budget/adjustment BudgetController@manualAdjustment

GET    /requisitions                   RequisitionController@index
POST   /requisitions                   RequisitionController@store
GET    /requisitions/{id}              RequisitionController@show
POST   /requisitions/{id}/transition   RequisitionController@transition
GET    /requisitions/review-queue      RequisitionController@reviewQueue
POST   /requisitions/{id}/attachments  RequisitionController@addAttachment

GET    /approvals/steps                ApprovalController@steps
POST   /approvals/steps/{id}/resolve   ApprovalController@resolve

GET    /finance/{projectId}            FinanceController@dashboard
POST   /finance/cash-requests          CashController@request
POST   /finance/cash-requests/{id}/approve  CashController@approve
POST   /finance/cash-requests/{id}/reject   CashController@reject
POST   /finance/cash-requests/{id}/receive  CashController@receive
GET    /finance/reconciliation/{projectId}  CashController@reconciliation
POST   /finance/expenses               ExpenseController@store
GET    /finance/expenses               ExpenseController@index
GET    /finance/overhead               ExpenseController@overhead

GET    /procurement/suppliers          SupplierController@index
POST   /procurement/suppliers          SupplierController@store
POST   /procurement/purchase-orders    PurchaseOrderController@store
POST   /procurement/goods-receipts     GoodsReceiptController@store

GET    /inventory/items                InventoryController@items
GET    /inventory/balances             InventoryController@balances
POST   /inventory/issue                InventoryController@issue
POST   /inventory/transfer             InventoryController@transfer
POST   /inventory/adjust               InventoryController@adjust

GET    /payroll/{projectId}            PayrollController@index
POST   /payroll/generate               PayrollController@generate
POST   /payroll/{id}/post              PayrollController@post
GET    /payroll/employees              EmployeeController@index
POST   /payroll/employees              EmployeeController@store
POST   /payroll/attendance             AttendanceController@store

GET    /equipment                      EquipmentController@index
POST   /equipment                      EquipmentController@store
POST   /equipment/assignments          EquipmentController@assign
POST   /equipment/maintenance          EquipmentController@maintenance
POST   /equipment/fuel                 EquipmentController@fuel

GET    /projects/{id}/valuations       ValuationController@index
POST   /projects/{id}/valuations       ValuationController@store
POST   /valuations/{id}/certify        ValuationController@certify

GET    /reports/preview/{slug}         ReportController@preview
GET    /reports/export/{slug}          ReportController@export
GET    /reports/schedules              ReportController@schedules
POST   /reports/schedules              ReportController@createSchedule

GET    /notifications                  NotificationController@index
GET    /notifications/unread-count     NotificationController@unreadCount
POST   /notifications/{id}/read        NotificationController@markRead

GET    /audit                          AuditController@index

GET    /settings/ui                    SettingsController@ui
POST   /admin/settings/ui              AdminController@updateUI
GET    /admin/users                    AdminController@users
POST   /admin/users                    AdminController@createUser
POST   /auth/impersonate/{userId}      AuthController@impersonate
```

All routes except `/login` require authentication.
All routes call `$this->authorize()` via Policy before touching data.
Finance routes use `budgets:*` permission module (not `finance`).

---

## Frontend structure

```
resources/js/
├── Pages/
│   ├── Auth/Login.tsx
│   ├── Dashboard.tsx
│   ├── Projects/{Index,Create,Show}.tsx
│   ├── BOQ/{Index,Import}.tsx
│   ├── Budgets/Show.tsx
│   ├── Requisitions/{Index,Create,Show,Review}.tsx
│   ├── Finance/{Index,CashFlow,Reconciliation,Expenses,Overhead}.tsx
│   ├── Procurement/{Index,Suppliers,PurchaseOrders,GoodsReceipts}.tsx
│   ├── Inventory/{Stock,Issues,Transactions}.tsx
│   ├── Payroll/{Index,Attendance,Generate}.tsx
│   ├── Equipment/{Index,Assignments,Maintenance,Fuel}.tsx
│   ├── Projects/[id]/{Valuations,BOQ,Budget,QS}.tsx
│   ├── Reports/{Index,Show,Schedules}.tsx
│   ├── Admin/{Users,Settings}.tsx
│   └── Audit/Index.tsx
├── Components/
│   ├── Layout/AppShell.tsx        — sidebar + header, permission-filtered nav
│   ├── Layout/PageHeader.tsx
│   ├── Shared/DataPanel.tsx
│   ├── Shared/StatusBadge.tsx
│   ├── Shared/ExportButton.tsx
│   └── Domain/                    — BOQTree, RequisitionTimeline, etc.
└── lib/
    ├── api.ts                     — typed request wrapper
    ├── permissions.ts             — hasPermission(), nav filtering
    └── formatters.ts              — currency (TZS), dates, percentages
```

Pages use Inertia's `useForm` for mutations and `usePage().props`
for server-provided data. No TanStack Query — Inertia handles
data flow. No client-side API calls on initial page load.

Share via `HandleInertiaRequests::share()`:
- `auth.user` — authenticated user + roles
- `currentProject` — active project from session
- `unreadNotificationCount`
- `uiSettings` — app name, tagline from system_settings
- `flash` — success/error messages

---

## Known things to watch

1. **Two-write user creation** — always atomic, always both
   `central_users` AND tenant `users` in one transaction.
2. **Valuation deductions** — applied in fixed order, advance recovery
   respects cumulative prior recoveries. Don't reorder the deduction
   slice.
3. **Indirect expenses** — no `project_id`, no `BudgetTransaction`.
   Reports must distinguish them from direct project costs.
4. **BOQ revision immutability** — confirm copy-forward behavior
   before building variation history on top of it.
5. **Frontend auth is client-side gate only** — the API Policy check
   is the real enforcement. Never rely on hiding a button as security.
6. **Impersonation audit** — actor_id in AuditLog = impersonator's ID.
   Flag this when tracing actions in the audit trail.
7. **`forceDelete` must not exist** in application code — only in
   test factories if needed. Grep before final deploy.

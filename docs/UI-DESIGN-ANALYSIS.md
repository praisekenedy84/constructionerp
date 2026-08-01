# Construction ERP — UI Design Analysis

> **Purpose of this document**  
> This is a living design brief of the current product UI. It describes what exists, how it feels, what it optimizes for, and where it is incomplete. Another AI (or designer) should use it to propose concrete improvements—tokens, components, layouts, interaction patterns, accessibility, and visual direction—without needing to reverse-engineer the codebase first.
>
> **How to use this as an AI critic**  
> Prefer suggestions that: (1) respect the multi-tenant construction-ERP domain, (2) build on the existing React/Inertia/Tailwind stack, (3) cite which section of this brief they address, and (4) distinguish *quick consistency fixes* from *directional redesign*. Do not suggest replacing the stack unless the brief’s constraints make that necessary.

---

## 0. Revision log — what changed since the last review

**Revision 2 (current).** Since the first analysis, a significant **cash-control redesign** landed (commits `0bb232f`, `921e591`, `4e1cfcc`, `e4b9868`). The UI now models **two separate wallets** and had to grow new surfaces to explain that separation to users.

### Newly added

| Change | Where | Design significance |
| --- | --- | --- |
| **Organization Cash page** | `Pages/Finance/OrganizationCash.tsx` | Brand-new screen type: wallet dashboard + policy card + accordion lifecycle + ledger table |
| **Finance nav grew to 4 children** | `MenuCatalog.php` | Fund Approvals · **Organization Cash** · Expenses · Overhead |
| **`PaymentMethodSelect` primitive** | `Components/ui/payment-method-select.tsx` | First shared `<select>`; dark-mode aware; domain-scoped (cash / mobile / bank) |
| **Dialog form conventions** | `Components/ui/dialog-form.tsx` | `DialogFormActions`, `DialogFormFields`, `confirmDiscardIfDirty` now shared across Finance modals |
| **Expense edit + delete** | `Pages/Finance/Expenses.tsx` | Row-level pencil/trash ghost buttons; destructive copy warns cash is returned |
| **Wallet-guard KPIs** | `Pages/Finance/Overhead.tsx` | “Organization Cash on Hand” shown *before* spending; available-cash math accounts for the row being edited |
| **Cash-on-hand KPI on requisition detail** | `Pages/Requisitions/Show.tsx` | Approver sees liquidity next to the amount being approved |
| **Profit KPI** | `Pages/Projects/Show.tsx` | Project header KPI strip now Net Budget · Remaining · Contract · Profit |
| **Org vs project rows** | `Pages/Finance/FundApprovals.tsx` | Rows render `ORG` / “Organization (general)” when no project |

### Previously flagged issues that are now **fixed**

- **Fund Approvals table responsiveness** — now wrapped in `overflow-x-auto` with `min-w-[1100px]` (§11 P0 partially addressed).
- **Fund request creation** — moved from inline page form into a proper `Dialog` with standardized footer actions.
- **Modal footer drift** — Finance modals now share `DialogFormActions` instead of hand-rolled button rows.
- **Unsaved-work loss** — `confirmDiscardIfDirty` guards dialog dismissal.

### Previously flagged issues that are **still open**

- **Dark mode is still incomplete, and the new screens made it worse.** `OrganizationCash.tsx` contains **zero** `dark:` variants — a whole new page is light-only. Same for the Fund Approvals KPI strip (`bg-white` hardcoded).
- **Inline row approval forms remain** in `FundApprovals.tsx`: approve/amend and reject still expand *inside* the actions cell (§7, §11 P1).
- **No breadcrumbs, no toasts, no skeletons** — verified absent across `resources/js`.
- **`window.confirm` still used in ~19 places**, now carrying financially consequential copy such as *“Delete expense of X? The amount will be returned to cash on hand.”* Good message, wrong container.

### New issues introduced by this round

- **Duplicate timeline implementations.** `OrganizationCash.tsx` builds its own vertical lifecycle rail (`border-l` + absolutely positioned dots) instead of reusing `Domain/RequisitionTimeline.tsx`.
- **Select styling is now forked three ways**: `PaymentMethodSelect`, the inline `<select>` in the expense dialog (same class string, copy-pasted), and `ListToolbar`’s own selects.
- **Wallet vocabulary is inconsistent in UI copy**: “float”, “wallet”, “organization cash”, “cash on hand”, and “Received (Floated)” all appear. Users must infer these are related concepts.
- **`InsufficientCashException`** introduces a new *money-guard failure* state with no dedicated visual treatment — it lands in the generic inline flash banner.

---

## 1. Product identity (what this UI is trying to be)

This is a **multi-tenant construction ERP** for project finance, requisitions, BOQ/budgets, procurement, inventory, payroll, equipment, and reporting. The UI serves site managers, approvers, finance staff, and tenant admins—not consumers.

**Intended personality today**

| Trait | How it shows up |
| --- | --- |
| Restrained enterprise | Slate surfaces, blue primary, soft cards, few decorative flourishes |
| Operational density | Wide tables, status pills, codes in monospace, right-aligned money |
| Domain-aware | Requisition type-adaptive forms, BOQ trees, inventory “numbered flow”, timelines |
| Permission-first | Nav and actions appear only when authorized |
| Lightly branded | Tenant can change app name + tagline; no logo/accent theming yet |

**Emotional / brand read (as shipped)**  
The product reads as a competent internal tool: calm, tabular, slightly generic SaaS-admin. It does not yet feel strongly “construction industry” (no material textures, site photography, hardhat/site vernacular, or industrial typography). Brand expression is mostly the Building2 icon + Plus Jakarta Sans + blue-700. Platform admin uses a violet accent to differentiate “system oversight” from tenant work.

**Open critique prompt**  
*Is “calm generic SaaS admin” the right destination for a construction ERP, or should the visual language signal field operations, trust, and money-control more distinctly—without sacrificing density?*

---

## 2. Technology canvas (constraints for suggestions)

| Layer | Choice |
| --- | --- |
| App model | Laravel + Inertia.js SPA (React 19 + TypeScript) |
| Styling | Tailwind CSS 4 (`@theme` in CSS; no classic `tailwind.config`) |
| Icons | Lucide React |
| Charts | Recharts |
| Primitives | Radix (dialog/sheet, label, collapsible) + CVA/clsx/tailwind-merge |
| Entry | `resources/js/app.tsx`, `resources/css/app.css` |
| Host blade | `resources/views/app.blade.php` (fonts, dark-mode boot script, Ziggy routes) |

**Implications for suggestions**

- Prefer Tailwind utility patterns and shared React components over a new CSS framework.
- Design tokens belong in `@theme` / shared class maps / CVA variants—not scattered one-off hex values.
- Page routes are Inertia page components under `resources/js/Pages/**`.
- Avoid proposing Bootstrap, Vue, or DataTables; lists are hand-built tables + `ListToolbar` + Laravel pagination.

---

## 3. Visual system as implemented

### 3.1 Typography

- **Family:** Plus Jakarta Sans (Bunny Fonts), wired as `--font-sans`.
- **Hierarchy in practice:**
  - Page title: `text-2xl font-bold` (`PageHeader`, dashboard welcome)
  - Shell top-bar title: `text-lg font-semibold`
  - Panel title: `text-sm font-semibold`
  - Body: `text-sm`
  - Meta / empty: `text-xs` or `text-slate-500`
  - Codes / IDs: `font-mono`
- **Tone:** Modern geometric sans; professional but not industrial or editorial.

**Critique prompt**  
*Is one sans enough for an ERP that mixes dense ledgers and executive dashboards? Should numeric columns use a tabular-nums treatment globally? Should display titles and ledger body diverge?*

### 3.2 Color & semantic meaning

**Structural palette (Tailwind Slate + Blue)**

| Role | Light | Dark (where applied) |
| --- | --- | --- |
| Canvas | `slate-50` | `slate-950` |
| Surface / cards | white | `slate-900` |
| Borders | `slate-200` | `slate-700` / `800` |
| Primary text | `slate-900` | white / `slate-200` |
| Secondary text | `slate-500` / `600` | `slate-400` |
| Primary action | `blue-700` → hover `blue-800` | same blue (often) |
| Focus ring | `blue-600` | same |
| Active nav | `blue-50` + `blue-700` | partially mirrored |
| Platform accent | violet family | — |
| Success | green | — |
| Warning / amendment | amber | — |
| Danger | red | — |

**Status badges** (`StatusBadge.tsx`) — pastel pill backgrounds with readable text labels (color is never the only signal):

- success → `bg-green-100 text-green-800`
- warning → `bg-amber-100 text-amber-800`
- danger → `bg-red-100 text-red-800`
- info → `bg-blue-100 text-blue-800`
- neutral/default → slate pastel

**Chart colors** (`lib/chart-colors.ts`): blue `#1d4ed8`, green `#059669`, amber `#d97706`, violet `#7c3aed`, red `#dc2626`, cyan `#0891b2`, slate `#64748b`, pink `#db2777`, muted `#94a3b8`.

**Critical gap:** Dark mode is **shell-complete, page-incomplete**. Many screens hardcode light surfaces (`bg-white`, `text-slate-900`, light-only selects, light chart axes). Theme toggle can produce a mixed light-content / dark-chrome experience.

**Trend warning (rev 2):** the gap is *widening*, not closing. Shared primitives added recently (`PaymentMethodSelect`, `DialogFormActions`) do ship dark variants, but the newest full page — `Finance/OrganizationCash.tsx` — has none at all. Dark mode is being maintained at the component layer and ignored at the page layer, which means every new screen adds debt.

Rough current coverage (count of `dark:` occurrences per page file): Inventory Items 16, Platform Tenants Show 16, Platform Dashboard 24, Auth Login 9 … versus Dashboard 0, Projects Index 0, Requisitions Index 0, Finance OrganizationCash 0.

### 3.3 Shape, elevation, spacing

| Element | Convention |
| --- | --- |
| Controls | `rounded-md`, typically `h-10` (sm `h-9`) |
| Cards / panels / dialogs | `rounded-xl` |
| Status pills | `rounded-full` |
| Card recipe | `rounded-xl border border-slate-200 bg-white shadow-sm` |
| Hover lift (KPIs) | `hover:shadow-md` |
| Dialog | `shadow-xl` |
| Page rhythm | `space-y-6` (lists/forms), `space-y-8` (dashboard) |
| Content padding | shell `p-4 sm:p-8`; panel body often `p-6` |
| Table header | `bg-slate-50 text-xs text-slate-500` |
| Table cells | `px-6 py-4` |
| Empty table cell | `px-6 py-12 text-center text-slate-500` |

**Overall density:** Medium-high—appropriate for ERP, but some action cells (especially fund approvals) expand into full inline forms and break the calm grid.

### 3.4 Motion

Present but modest:

- Sheet enter/exit (left/right/top/bottom) and overlay fade in `app.css`
- Collapsible height animation for sidebar sections
- Shadow transition on dashboard KPI cards
- Inertia top progress bar color: `#1e40af` (blue-800-ish)

No page-transition choreography, skeleton shimmer, or intentional micro-interaction language beyond Radix motion.

---

## 4. Layout architecture

### 4.1 Tenant shell — `AppShell.tsx`

```
┌─ [optional amber impersonation banner] ─────────────────────┐
│ ┌──────────┐ ┌─ sticky top bar (h-16) ─────────────────────┐ │
│ │ Sidebar  │ │ title | theme | bell | user | logout        │ │
│ │ w-64     │ ├─────────────────────────────────────────────┤ │
│ │ brand    │ │ main: flash banners + page content          │ │
│ │ nav      │ │                                             │ │
│ │ (scroll) │ │                                             │ │
│ └──────────┘ └─────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

- Desktop: fixed left sidebar, content `md:pl-64`.
- Mobile: sidebar becomes Radix **Sheet** drawer from hamburger.
- Brand block: Building2 icon + configurable name/tagline.
- Nav: permission-filtered, collapsible parents; expansion persisted in `localStorage` (`crf-sidebar-expanded`).
- **No breadcrumbs** anywhere. Context comes from top-bar title, `PageHeader`, and local tabs (project / inventory / admin).

### 4.2 Platform shell — `PlatformShell.tsx`

Violet-accented oversight UI. Fixed 256px sidebar always on; **not responsive** (always `pl-64`). Distinct product “mode” from tenant ERP.

### 4.3 Auth — `Login.tsx`

Desktop 50/50 split: dark blue gradient brand panel + form (`max-w-md`). Below `lg`, brand collapses to a compact header. One of the few screens with deliberate atmosphere.

### 4.4 Canonical page compositions

**List pages**

1. `PageHeader` (title, description, primary actions)
2. `ListToolbar` (search, dates, sort, filters — explicit submit, not live debounce)
3. White bordered table panel
4. `PaginationLinks`

**Detail pages** (e.g. Requisition Show, Project Show)

- KPI strip or summary cards
- Tabbed or sectioned panels (`DataPanel`)
- Domain widgets (timeline, BOQ effect, attachments)
- Contextual action clusters (approve / amend / reject / fulfill)

**Forms**

- Inertia `useForm`
- `space-y-6`, often `max-w-2xl` / `max-w-3xl`
- Sections as separate white cards
- Inline field errors under controls
- Submit disabled + “Saving…” label while processing
- Strong pattern: live financial summary on project form

**Modal-heavy modules**

Inventory, procurement, payroll, and admin often create/edit in custom dialogs rather than full pages.

---

## 5. Component inventory (design system maturity)

### 5.1 Present and relatively consistent

| Component | Role |
| --- | --- |
| `ui/button` | default / outline / ghost; sizes via CVA |
| `ui/input` | text input + focus ring + dark variants |
| `ui/label` | Radix label |
| `ui/amount-input` | currency with thousands separators |
| `ui/password-input` | show/hide |
| `ui/dialog` | modal shell (overlay, Esc, scroll lock, initial focus) |
| `ui/dialog-form` | `DialogFormActions` (Cancel + submit with processing label), `DialogFormFields` (`space-y-4`), `confirmDiscardIfDirty` |
| `ui/payment-method-select` | shared payment-method `<select>` — cash / mobile / bank, dark-aware, `optional` prop |
| `ui/sheet` | mobile drawer |
| `ui/collapsible` | nav sections |
| `Shared/PageHeader` | title + description + actions |
| `Shared/DataPanel` | canonical card |
| `Shared/ListToolbar` | server-driven filters |
| `Shared/PaginationLinks` | Laravel paginator UI |
| `Shared/StatusBadge` | semantic status map |
| `Shared/LinkButton` | Inertia link as button |
| `Shared/ThemeToggle` | light/dark |
| `Shared/PermissionDenied` | polished 403/404/500/503 |
| Charts (`SimpleBar/Line/Pie`) | Recharts wrappers |
| Domain: `BoqTree`, `RequisitionTimeline`, `AmendRequisitionForm` | specialized |
| Nav: `InventoryNav`, `AdminNav` | contextual secondary nav |

### 5.2 Missing or ad hoc (high-value suggestion surface)

- Textarea, **generic** Select/Combobox, Checkbox, Radio primitives — note `PaymentMethodSelect` solved exactly one select and its class string is now copy-pasted into other selects rather than extracted
- Tooltip
- Toast / snackbar system
- Skeleton / spinner / table loading overlay
- Destructive button variant (today: custom red outline/ghost classes)
- Empty-state component (Inventory has good ones; most lists are one sentence)
- Breadcrumb component
- Confirm dialog (today: native `window.confirm` for many destructive paths, including money-reversing ones)
- Focus-trap completeness on custom dialog (Sheet/Radix is stronger)
- **Timeline component** — `RequisitionTimeline` exists but `OrganizationCash` re-implements a second, visually different lifecycle rail
- **Wallet / balance KPI component** — “cash on hand” cards are hand-built on at least five screens (Finance Index, Overhead, OrganizationCash, Reconciliation, Requisitions Show)

**Critique prompt**  
*Which 5 primitives would most reduce visual drift if introduced next?*

---

## 6. Information architecture & navigation

Server catalog: `app/Support/MenuCatalog.php`

**Top-level order (tenant)**

1. Dashboard  
2. Projects  
3. Requisitions  
4. Finance → Fund Approvals, **Organization Cash**, Expenses, Overhead  
5. Procurement → Suppliers, POs, Goods Receipts  
6. Inventory → Items, On Hand, Hand Over, History  
7. Payroll → Employees, Attendance, Generate, Runs  
8. Equipment → Registry, Assignments, Maintenance, Fuel  
9. Reports  
10. Audit  
11. Admin  

Catalog metadata includes conceptual groups (Core, Operations, Finance, Supply Chain, HR, Insights, Administration), but **`AppShell` does not render group headings**—the sidebar is a long flat sequence of modules.

**Contextual nav that helps**

- Project tabs: Overview · BOQ · Budget · Requisitions · Finance · Reports  
- Inventory numbered workflow steps  
- Admin tabs: Users · Staff · Permissions · Menu · Branding  

**Wayfinding gaps**

- No breadcrumbs on nested paths (project → BOQ → edit/import, report preview, payroll run detail, valuations).
- Sticky shell title often **duplicates** `PageHeader` title → vertical redundancy.
- Deep finance/approval workflows can strand users without a clear “back to parent entity” path.
- **Finance is now a four-screen mental model** (approvals → org cash → expenses → overhead) with cross-links stitched in as header buttons (“Organization Cash”, “Record Overhead”, “Request / Approve Funds”). Those buttons are doing the job a proper sub-nav should do — compare with `InventoryNav`’s numbered flow, which the finance module would benefit from copying.

---

## 7. Module-by-module design character

Use this section when suggesting screen-specific UX.

| Module | Design character | Stress points |
| --- | --- | --- |
| **Dashboard** | Welcome + 4 linked KPI cards + 2 charts + quick links | Light-only cards; title duplication; generic quick-link chips |
| **Projects** | Classic list → centered multi-section form → tabbed show; KPI strip now Net Budget · Remaining · Contract · **Profit** | Table may clip on mobile; form is a strong reference pattern |
| **BOQ** | Dense expandable tree, many columns, bulk select | Highest visual density; mobile/scroll strategy critical |
| **Requisitions** | Adaptive line types; rich Show with timeline & approval panels | Create form complexity; review/fulfill queues need scanability |
| **Fund Approvals** | 5-up KPI strip + filters + horizontally scrollable `min-w-[1100px]` table + dialog for *creating* requests, but still **inline** approve/reject/receipt forms | Row height explosion on decisions; 10 columns; KPI cards light-only |
| **Organization Cash** *(new)* | Wallet dashboard: 4 KPIs → “Where cash was used” + “Allowed Uses” policy card → accordion **Fund Lifecycle** → Recent Uses table | Zero dark-mode support; bespoke timeline; accordion header is a full-width `<button>` with no chevron affordance |
| **Overhead** | Shows spendable balance *before* the spend dialog; edit-aware available-cash math | Good money-safety pattern worth generalizing |
| **Expenses** | Ledger + create/edit dialog + row edit/delete ghost buttons | Delete uses `window.confirm` for a cash-reversing action |
| **Inventory** | Numbered flow nav; modal ops; better empty states | Strongest workflow storytelling in the product |
| **Procurement** | List + modal create | Pattern consistency with inventory |
| **Payroll / Equipment** | Registry + operational subpages | Follow list/modal conventions; watch table width |
| **Reports / Audit** | Catalog + preview + schedules | Preview chrome / export affordances |
| **Admin** | Tabbed settings; branding limited to name/tagline | Light-only secondary nav; weak white-label story |
| **Platform** | Violet shell; tenant lifecycle & impersonation | Desktop-only shell; needs mobile parity |

### 7.1 Emerging motifs worth naming (or killing)

The cash work introduced patterns that are not yet part of the documented system. An AI reviewer should decide whether to **promote them into the design system** or **retire them**:

1. **The policy card.** A `DataPanel` whose body is prose rules rather than data — “Allowed Uses” on Organization Cash, “What each figure means” on Reconciliation. This is genuinely useful in an ERP where money movement is constrained by rules. It currently has no component, no icon language, and no visual distinction from data panels.

2. **The balance-before-spend guard.** Overhead and Requisition Show now display available cash next to the action that will consume it. This is one of the strongest UX ideas in the product and it exists in exactly two places, styled by hand.

3. **The accordion ledger.** Organization Cash renders fund requests as expandable rows revealing a lifecycle rail, instead of a table. It competes with the table pattern used everywhere else for the same kind of record.

4. **Wallet-scoped vocabulary.** “Project float” vs “organization cash” is a real domain distinction, but the interface expresses it with at least five different words and no consistent color, icon, or badge.

**Representative files for visual critique (priority order)**

1. `resources/js/Components/Layout/AppShell.tsx`  
2. `resources/js/Components/ui/button.tsx`, `input.tsx`, `dialog.tsx`  
3. `resources/js/Components/Shared/{PageHeader,DataPanel,ListToolbar,StatusBadge}.tsx`  
4. `resources/js/Pages/Dashboard.tsx`  
5. `resources/js/Pages/Projects/Index.tsx`, `ProjectForm.tsx`, `Show.tsx`  
6. `resources/js/Pages/Requisitions/Show.tsx`, `Create.tsx`  
7. `resources/js/Pages/Finance/FundApprovals.tsx`  
8. `resources/js/Pages/Finance/OrganizationCash.tsx` *(newest screen; no dark mode; new motifs)*  
9. `resources/js/Pages/Finance/Overhead.tsx` *(balance-before-spend guard)*  
10. `resources/js/Pages/Inventory/Stock.tsx`  
9. `resources/js/Components/Domain/BoqTree.tsx`  
10. `resources/js/Pages/Auth/Login.tsx`  
11. `resources/js/Components/Layout/PlatformShell.tsx`

---

## 8. Interaction & feedback language

| Concern | Current behavior | Design smell |
| --- | --- | --- |
| Navigation progress | Inertia blue bar | Fine; sole global progress cue |
| Success/error flash | Inline banners in shell | Not dismissible; no toast |
| Form errors | Text under fields | Rarely wired with `aria-describedby` / `role="alert"` |
| Destructive confirm | `window.confirm` (~19 sites) | Breaks visual language; weak severity hierarchy; now used for cash-reversing deletes |
| Discard unsaved work | `confirmDiscardIfDirty` in dialogs | Centralized — good — but still a native OS prompt |
| Business-rule failure | `InsufficientCashException` → generic flash banner | A “you cannot spend this” event deserves stronger, in-context treatment than a page-top banner |
| Loading | Disabled button + “…ing” label | No skeletons; filter submits can feel abrupt |
| Empty lists | Inconsistent: Inventory and Organization Cash good; many “No X found” | No shared empty-state |
| Filters | Explicit Apply via GET | Predictable; not live-search |
| Nested interactives | Occasional `<Link><Button>` (still present in new Finance headers) | Invalid markup risk; prefer `asChild` |
| Disclosure | Accordion rows (Organization Cash) toggled by full-width `<button>` | No chevron/expanded affordance; no `aria-expanded` |

---

## 9. Accessibility snapshot

**Strengths**

- Many icon buttons have `aria-label`
- Status text accompanies color
- Labels on most inputs
- Mobile nav via Radix Sheet
- Dialog has role + titled content
- Pre-hydration dark-mode script reduces theme flash

**Weaknesses**

- Custom dialog: incomplete focus trap / focus restore
- Charts lack textual/table fallback
- Error association and live regions incomplete
- Some labels missing `htmlFor`
- Contrast risk: `text-slate-400` meta, pale status pastels on white
- Platform shell unusable on small viewports

---

## 10. Strengths to preserve

When suggesting changes, **do not casually discard**:

1. Predictable enterprise page grammar (header → toolbar → panel → pagination).  
2. Appropriate operational density and money/code formatting discipline.  
3. Domain storytelling (timelines, inventory steps, adaptive requisition lines, BOQ tree).  
4. Permission-aware chrome.  
5. Status badges with text labels.  
6. Polished system error pages.  
7. Amount input with live formatting.  
8. Tenant shell mobile sheet pattern.  
9. Separation of platform vs tenant visual modes.  
10. **Explanatory page descriptions.** `PageHeader` descriptions now teach policy in one sentence (“…paid only from organization cash on hand — not from project floats”). This is unusually good ERP copy; keep it.  
11. **Balance shown before spend** (Overhead, Requisition approve). Preserve and generalize.  
12. **Standardized dialog footers** via `DialogFormActions`.

---

## 11. Weaknesses & opportunity map (suggestion backlog seeds)

Ranked by impact on coherence and trust:

| Priority | Opportunity | Status at rev 2 | Why it matters |
| --- | --- | --- | --- |
| P0 | Complete dark-mode coverage or gate the toggle until ready | **Regressed** — newest page ships zero `dark:` | Mixed themes destroy polish |
| P0 | Responsive table strategy (`overflow-x-auto`, column priority, card fallbacks) | **Partial** — applied to Fund Approvals, BOQ, Reports, Permissions, BOQ Import only | ERP tables are the product |
| P0 | Wallet legibility: one vocabulary, one badge, one color for project float vs organization cash | **New** | Users are now spending from two pots with no visual system to tell them apart |
| P1 | Tokenize color/radius/shadow in `@theme` + shared recipes | Open | Stops drift; enables tenant theming later |
| P1 | Fill primitive gaps (generic Select, Textarea, Checkbox, destructive Button, EmptyState, ConfirmDialog, Toast, Skeleton) | **Partial** — `PaymentMethodSelect` + `DialogFormActions` landed | Consistency + a11y |
| P1 | Replace inline row approve/reject in Fund Approvals with drawer/modal | Open — create flow moved to dialog, decision flow did not | Scanability + mobile |
| P1 | Promote the “policy card” and “balance-before-spend” motifs into real components | **New** | Two of the best ideas in the product exist only as one-offs |
| P2 | Breadcrumbs + de-duplicate shell vs page titles | Open | Wayfinding |
| P2 | Finance sub-nav (like `InventoryNav`) instead of header cross-link buttons | **New** | Four-screen money model needs a visible spine |
| P2 | Sidebar group headings from MenuCatalog | Open | Cognitive chunking |
| P2 | PlatformShell mobile parity | Open | Admin on the go |
| P2 | Stronger tenant branding (logo, accent, favicon) | Open | Multi-tenant product promise |
| P2 | Unify timelines — one component for requisition + fund lifecycle | **New** | Two implementations already diverging |
| P3 | Empty-state system, loading skeletons, dismissible/toasts | Open | Feedback quality |
| P3 | Dedicated treatment for money-guard errors (insufficient cash) | **New** | Blocking financial errors deserve more than a flash banner |
| P3 | Visual identity direction for construction (without gimmicks) | Open | Differentiation |
| P3 | Locale/currency preference surfaces (TZS assumptions visible today) | Open — TZS still hardcoded in labels like “Amount (TZS)” | Regional product fit |

---

## 12. Design principles (proposed — for AI to affirm, refine, or replace)

These are **inferred goals**, not yet a written brand book. Critique them:

1. **Clarity over chrome** — every pixel should help money, status, or next action.  
2. **One grammar for lists, one for decisions** — lists stay scannable; approvals get dedicated spatial focus (not jammed into a cell).  
3. **Color carries status, text carries meaning** — keep badges labeled.  
4. **Density with rescue hatches** — dense by default; overflow/horizontal scroll/drawers before crushing columns.  
5. **Theme integrity** — light and dark are both first-class or dark is hidden.  
6. **Tenant identity without chaos** — name/logo/accent within a locked token system.  
7. **Accessible by construction** — labels, focus, confirmations, and loading states are part of the component API, not page afterthoughts.

---

## 13. Expressive “current state” vignette (for redesign prompts)

> Imagine opening the tenant app on a laptop. A cool slate canvas holds a white 256px sidebar with a building icon and a custom app name. Blue highlights the active nav item. The top bar sticks, showing the page title again—then the page repeats that title larger underneath. Dashboard KPI cards sit in a four-up grid: white, soft shadow, Lucide icons tinted blue/amber/green. Charts in rounded panels use Recharts blues and muted greys. Everything feels orderly and a bit anonymous.
>
> Switch to Fund Approvals and the calm breaks: five KPI tiles, then a ten-column table that now scrolls sideways rather than crushing itself — an improvement. Creating a request opens a tidy dialog. But approving one still unfolds a form *inside* the row, shoving the table apart. Rows without a project simply say `ORG`, and you are expected to know that means the company wallet rather than a site.
>
> Follow that to the new Organization Cash screen and the product briefly becomes something better: four balance tiles, a card that plainly lists what this money may be spent on, an expandable fund lifecycle, a ledger of recent uses. It explains itself. Then you toggle dark mode and the entire page stays daylight white while the shell around it goes night — the newest screen in the system has no dark styling at all.
>
> Elsewhere the money language keeps shifting under you: float, wallet, organization cash, cash on hand, “Received (Floated)”. Delete an expense and Windows — not the product — asks whether you are sure, warning that the amount returns to cash on hand. On a phone, the tenant shell drawer works, but many tables still threaten to clip.
>
> Inventory feels more intentional—numbered steps explain the stock journey; empty states tell you what to create next. Login is the only screen that tries for atmosphere (deep blue gradient). Platform admin is a violet twin of the same admin pattern, but forgets mobile.
>
> The system knows construction workflows deeply. Visually, it still speaks fluent “default Tailwind admin.”

---

## 14. Requested output format for AI suggestions

When proposing improvements, structure responses as:

1. **Diagnosis** — which section(s) of this brief (e.g. §3.2, §7 Fund Approvals, §11 P0).  
2. **Recommendation** — specific, implementable in this stack.  
3. **Impact** — UX, a11y, consistency, or brand.  
4. **Effort band** — S / M / L.  
5. **Preserve** — what existing pattern must remain.  
6. **Optional mock description** — layout sketch in ASCII or component tree, not vague adjectives.

Avoid generic advice (“improve UX”, “make it modern”). Prefer token names, component APIs, and screen targets.

---

## 15. Document metadata

| Field | Value |
| --- | --- |
| Product | Construction ERP (Laravel + Inertia React) |
| Revision | **2** — includes the project-float vs organization-cash redesign |
| Code state reviewed | through commit `0bb232f` *Separate organization cash from project floats and enforce scoped spending* |
| Analysis scope | Tenant app, platform admin, auth, shared design system |
| Code roots | `resources/js/**`, `resources/css/app.css`, `app/Support/MenuCatalog.php` |
| Not in scope | Marketing site, PDF export polish beyond noting export Blade exists |
| Audience | Designers, engineers, and AI agents proposing UI evolution |
| Refresh trigger | Any new page under `resources/js/Pages/**`, any change to `MenuCatalog`, or any new `Components/ui` primitive |

---

*End of analysis. Treat contradictions between this document and the code as code-wins; update this brief when the system of record changes.*

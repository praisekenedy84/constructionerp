<?php

namespace App\Http\Controllers;

use App\Enums\CompanyDebtStatus;
use App\Enums\CompanyDebtType;
use App\Http\Requests\StoreCompanyDebtPaymentRequest;
use App\Models\CompanyDebt;
use App\Models\MoneyAccount;
use App\Services\CompanyDebtService;
use App\Services\MoneyAccountService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyDebtController extends Controller
{
    public function __construct(
        private readonly CompanyDebtService $companyDebtService,
        private readonly MoneyAccountService $moneyAccountService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $status = $request->string('status')->toString();
        $type = $request->string('type')->toString();

        $listing = ListingQuery::for(
            CompanyDebt::query()
                ->with(['moneyAccount:id,name,type', 'recorder:id,name'])
                ->when(
                    $status !== '' && CompanyDebtStatus::tryFrom($status),
                    fn ($q) => $q->where('status', $status),
                )
                ->when(
                    $type !== '' && CompanyDebtType::tryFrom($type),
                    fn ($q) => $q->where('type', $type),
                ),
            $request,
        )
            ->search(['creditor_name', 'notes'])
            ->dateRange('occurred_at')
            ->sort(['occurred_at', 'outstanding_amount', 'original_amount', 'creditor_name'], 'occurred_at');

        $listingFilters = $listing->filters();
        if ($listingFilters instanceof \stdClass) {
            $listingFilters = (array) $listingFilters;
        }

        $openOutstanding = CompanyDebt::query()
            ->whereIn('status', [CompanyDebtStatus::Open, CompanyDebtStatus::PartiallyPaid])
            ->sum('outstanding_amount');

        return Inertia::render('Finance/Debts/Index', [
            'debts' => $listing->paginate(ListingQuery::PER_PAGE)
                ->through(fn (CompanyDebt $debt) => $this->companyDebtService->formatDebt($debt)),
            'filters' => array_merge($listingFilters, [
                'status' => $status,
                'type' => $type,
            ]),
            'summary' => [
                'open_outstanding' => bcadd((string) $openOutstanding, '0', 2),
                'open_count' => CompanyDebt::query()
                    ->whereIn('status', [CompanyDebtStatus::Open, CompanyDebtStatus::PartiallyPaid])
                    ->count(),
            ],
            'can_repay' => $request->user()->hasModulePermission('budgets', 'approve')
                || $request->user()->isSuperUser(),
            'status_options' => array_map(
                fn (CompanyDebtStatus $s) => ['value' => $s->value, 'label' => $s->label()],
                CompanyDebtStatus::cases(),
            ),
            'type_options' => array_map(
                fn (CompanyDebtType $t) => ['value' => $t->value, 'label' => $t->label()],
                CompanyDebtType::cases(),
            ),
        ]);
    }

    public function show(Request $request, CompanyDebt $debt): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $debt->load([
            'moneyAccount:id,name,type,balance',
            'recorder:id,name',
            'payments' => fn ($q) => $q->with(['moneyAccount:id,name,type', 'recorder:id,name'])->orderByDesc('occurred_at'),
        ]);

        $managerAccounts = collect($this->moneyAccountService->managerAccounts())
            ->map(fn (MoneyAccount $account) => $this->moneyAccountService->formatAccount($account))
            ->values()
            ->all();

        return Inertia::render('Finance/Debts/Show', [
            'debt' => $this->companyDebtService->formatDebt($debt),
            'payments' => $debt->payments
                ->map(fn ($payment) => $this->companyDebtService->formatPayment($payment))
                ->values()
                ->all(),
            'manager_accounts' => $managerAccounts,
            'can_repay' => ($request->user()->hasModulePermission('budgets', 'approve')
                || $request->user()->isSuperUser())
                && $debt->status->isPayable(),
        ]);
    }

    public function storePayment(StoreCompanyDebtPaymentRequest $request, CompanyDebt $debt): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'approve');

        $validated = $request->validated();
        $account = MoneyAccount::findOrFail($validated['money_account_id']);

        try {
            $this->companyDebtService->recordRepayment(
                $debt,
                (string) $validated['amount'],
                $account,
                $request->user(),
                [
                    'method' => $validated['method'] ?? null,
                    'reference_no' => $validated['reference_no'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'occurred_at' => $validated['occurred_at'] ?? now(),
                ],
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Debt repayment recorded.');
    }
}

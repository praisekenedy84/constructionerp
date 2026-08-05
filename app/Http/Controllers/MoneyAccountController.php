<?php

namespace App\Http\Controllers;

use App\Enums\MoneyAccountType;
use App\Http\Requests\DepositMoneyAccountRequest;
use App\Http\Requests\StoreMoneyAccountRequest;
use App\Models\AccountTransaction;
use App\Models\MoneyAccount;
use App\Services\MoneyAccountService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MoneyAccountController extends Controller
{
    public function __construct(
        private readonly MoneyAccountService $moneyAccountService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $this->moneyAccountService->ensureFinanceAccount($request->user());

        $accounts = MoneyAccount::query()
            ->orderByRaw("CASE WHEN type = 'finance' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get()
            ->map(fn (MoneyAccount $account) => $this->moneyAccountService->formatAccount($account));

        return Inertia::render('Finance/Accounts', [
            'accounts' => $accounts,
            'can_manage' => $request->user()->hasModulePermission('budgets', 'approve')
                || $request->user()->hasModulePermission('budgets', 'create')
                || $request->user()->isSuperUser(),
        ]);
    }

    public function store(StoreMoneyAccountRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'approve');

        $validated = $request->validated();
        $account = $this->moneyAccountService->createManagerAccount(
            $validated['name'],
            $request->user(),
            ['notes' => $validated['notes'] ?? null],
        );

        return back()->with('success', "Account \"{$account->name}\" created.");
    }

    public function deposit(DepositMoneyAccountRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'approve');

        $account = MoneyAccount::findOrFail($id);
        $validated = $request->validated();

        try {
            $this->moneyAccountService->deposit(
                $account,
                (string) $validated['amount'],
                $request->user(),
                [
                    'description' => $validated['description'] ?? null,
                    'reference_no' => $validated['reference_no'] ?? null,
                    'method' => $validated['method'] ?? null,
                    'occurred_at' => $validated['occurred_at'] ?? now(),
                ],
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Deposit recorded.');
    }

    public function managerTransactions(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        return $this->transactionsPage($request, MoneyAccountType::Manager, 'Finance/ManagerTransactions');
    }

    public function financeTransactions(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $this->moneyAccountService->ensureFinanceAccount($request->user());

        return $this->transactionsPage($request, MoneyAccountType::Finance, 'Finance/FinanceTransactions');
    }

    private function transactionsPage(Request $request, MoneyAccountType $type, string $component): Response
    {
        $accountId = $request->integer('account_id') ?: null;

        $accounts = MoneyAccount::query()
            ->where('type', $type)
            ->orderBy('name')
            ->get()
            ->map(fn (MoneyAccount $account) => $this->moneyAccountService->formatAccount($account));

        $listing = ListingQuery::for(
            AccountTransaction::query()
                ->whereHas('account', fn ($q) => $q->where('type', $type))
                ->when($accountId, fn ($q) => $q->where('money_account_id', $accountId))
                ->with(['account:id,name,type', 'relatedAccount:id,name,type', 'recorder:id,name']),
            $request,
        )
            ->search(['description', 'reference_no'])
            ->dateRange('occurred_at')
            ->sort(['occurred_at', 'amount', 'type'], 'occurred_at');

        $finance = $type === MoneyAccountType::Finance
            ? $this->moneyAccountService->ensureFinanceAccount($request->user())
            : null;

        $listingFilters = $listing->filters();
        if ($listingFilters instanceof \stdClass) {
            $listingFilters = (array) $listingFilters;
        }

        return Inertia::render($component, [
            'accounts' => $accounts,
            'transactions' => $listing->paginate(ListingQuery::PER_PAGE)
                ->through(fn (AccountTransaction $tx) => $this->moneyAccountService->formatTransaction($tx)),
            'filters' => array_merge($listingFilters, [
                'account_id' => $accountId ? (string) $accountId : '',
            ]),
            'summary' => [
                'balance' => $finance
                    ? (string) $finance->balance
                    : $this->sumAccountBalances($type),
                'account_count' => count($accounts),
            ],
            'can_deposit' => $type === MoneyAccountType::Manager
                && ($request->user()->hasModulePermission('budgets', 'approve') || $request->user()->isSuperUser()),
        ]);
    }

    private function sumAccountBalances(MoneyAccountType $type): string
    {
        $total = '0.00';
        foreach (MoneyAccount::query()->where('type', $type)->where('is_active', true)->get(['balance']) as $row) {
            $total = bcadd($total, (string) $row->balance, 2);
        }

        return $total;
    }
}

<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Enums\MoneyAccountType;
use App\Models\AccountTransaction;
use App\Models\CashAllocation;
use App\Models\MoneyAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MoneyAccountService
{
    public function ensureFinanceAccount(?User $creator = null): MoneyAccount
    {
        $existing = MoneyAccount::query()
            ->where('type', MoneyAccountType::Finance)
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return MoneyAccount::create([
            'name' => 'Finance Wallet',
            'type' => MoneyAccountType::Finance,
            'balance' => '0.00',
            'is_active' => true,
            'notes' => 'Shared operating wallet for project and company spending.',
            'created_by' => $creator?->id,
        ]);
    }

    /**
     * @param  array{bank_name?: string|null, notes?: string|null}  $opts
     */
    public function createManagerAccount(string $name, User $creator, array $opts = []): MoneyAccount
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Account name is required.');
        }

        $bankName = isset($opts['bank_name']) ? trim((string) $opts['bank_name']) : '';

        return MoneyAccount::create([
            'name' => $trimmed,
            'bank_name' => $bankName !== '' ? $bankName : null,
            'type' => MoneyAccountType::Manager,
            'balance' => '0.00',
            'is_active' => true,
            'notes' => $opts['notes'] ?? null,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * @param  array{description?: string|null, reference_no?: string|null, method?: string|null, occurred_at?: mixed}  $opts
     */
    public function deposit(MoneyAccount $account, string $amount, User $actor, array $opts = []): AccountTransaction
    {
        return DB::transaction(function () use ($account, $amount, $actor, $opts) {
            $account = MoneyAccount::lockForUpdate()->findOrFail($account->id);

            if (! $account->isManagerAccount()) {
                throw new \InvalidArgumentException('Deposits can only be recorded on company accounts.');
            }

            if (! $account->is_active) {
                throw new \InvalidArgumentException('Cannot deposit into an inactive account.');
            }

            $normalized = bcadd($amount, '0', 2);
            if (bccomp($normalized, '0', 2) !== 1) {
                throw new \InvalidArgumentException('Deposit amount must be greater than zero.');
            }

            $balance = bcadd((string) $account->balance, $normalized, 2);
            $account->update(['balance' => $balance]);

            return AccountTransaction::create([
                'money_account_id' => $account->id,
                'type' => AccountTransactionType::Deposit,
                'amount' => $normalized,
                'balance_after' => $balance,
                'description' => $opts['description'] ?? 'Deposit',
                'reference_no' => $opts['reference_no'] ?? null,
                'method' => $opts['method'] ?? null,
                'recorded_by' => $actor->id,
                'occurred_at' => $opts['occurred_at'] ?? now(),
            ]);
        });
    }

    /**
     * Move funds from a company account into the single finance wallet
     * when a fund request is approved.
     *
     * @param  array{method?: string|null, reference_no?: string|null}  $opts
     * @return array{out: AccountTransaction, in: AccountTransaction}
     */
    public function transferToFinance(
        MoneyAccount $source,
        string $amount,
        User $actor,
        CashAllocation $allocation,
        array $opts = [],
    ): array {
        return DB::transaction(function () use ($source, $amount, $actor, $allocation, $opts) {
            $source = MoneyAccount::lockForUpdate()->findOrFail($source->id);
            $finance = MoneyAccount::lockForUpdate()->findOrFail($this->ensureFinanceAccount($actor)->id);

            if (! $source->isManagerAccount()) {
                throw new \InvalidArgumentException('Fund transfers must come from a company account.');
            }

            if (! $source->is_active) {
                throw new \InvalidArgumentException('Cannot transfer from an inactive account.');
            }

            $normalized = bcadd($amount, '0', 2);
            if (bccomp($normalized, '0', 2) !== 1) {
                throw new \InvalidArgumentException('Transfer amount must be greater than zero.');
            }

            if (bccomp((string) $source->balance, $normalized, 2) < 0) {
                throw new \InvalidArgumentException(
                    "Insufficient balance in {$source->name} ({$source->balance}). Need {$normalized}."
                );
            }

            $sourceBalance = bcsub((string) $source->balance, $normalized, 2);
            $financeBalance = bcadd((string) $finance->balance, $normalized, 2);

            $source->update(['balance' => $sourceBalance]);
            $finance->update(['balance' => $financeBalance]);

            $description = "Fund request #{$allocation->id} approved";

            $out = AccountTransaction::create([
                'money_account_id' => $source->id,
                'type' => AccountTransactionType::TransferOut,
                'amount' => $normalized,
                'balance_after' => $sourceBalance,
                'description' => $description,
                'reference_no' => $opts['reference_no'] ?? $allocation->reference_no,
                'method' => $opts['method'] ?? $allocation->method,
                'related_account_id' => $finance->id,
                'reference_entity_type' => 'cash_allocation',
                'reference_entity_id' => $allocation->id,
                'recorded_by' => $actor->id,
                'occurred_at' => now(),
            ]);

            $in = AccountTransaction::create([
                'money_account_id' => $finance->id,
                'type' => AccountTransactionType::TransferIn,
                'amount' => $normalized,
                'balance_after' => $financeBalance,
                'description' => $description." — from {$source->name}",
                'reference_no' => $opts['reference_no'] ?? $allocation->reference_no,
                'method' => $opts['method'] ?? $allocation->method,
                'related_account_id' => $source->id,
                'reference_entity_type' => 'cash_allocation',
                'reference_entity_id' => $allocation->id,
                'recorded_by' => $actor->id,
                'occurred_at' => now(),
            ]);

            return ['out' => $out, 'in' => $in];
        });
    }

    /**
     * Debit the finance wallet for an expense or requisition disbursement.
     *
     * @param  array{
     *     description?: string|null,
     *     reference_no?: string|null,
     *     method?: string|null,
     *     reference_entity_type?: string|null,
     *     reference_entity_id?: int|null,
     *     occurred_at?: mixed
     * }  $opts
     */
    public function disburseFromFinance(string $amount, User $actor, array $opts = []): AccountTransaction
    {
        return DB::transaction(function () use ($amount, $actor, $opts) {
            $finance = MoneyAccount::lockForUpdate()->findOrFail($this->ensureFinanceAccount($actor)->id);

            $normalized = bcadd($amount, '0', 2);
            if (bccomp($normalized, '0', 2) !== 1) {
                throw new \InvalidArgumentException('Disbursement amount must be greater than zero.');
            }

            if (bccomp((string) $finance->balance, $normalized, 2) < 0) {
                throw new \InvalidArgumentException(
                    "Insufficient finance wallet balance ({$finance->balance}). Need {$normalized}."
                );
            }

            $balance = bcsub((string) $finance->balance, $normalized, 2);
            $finance->update(['balance' => $balance]);

            return AccountTransaction::create([
                'money_account_id' => $finance->id,
                'type' => AccountTransactionType::Disbursement,
                'amount' => $normalized,
                'balance_after' => $balance,
                'description' => $opts['description'] ?? 'Cash disbursement',
                'reference_no' => $opts['reference_no'] ?? null,
                'method' => $opts['method'] ?? null,
                'reference_entity_type' => $opts['reference_entity_type'] ?? null,
                'reference_entity_id' => $opts['reference_entity_id'] ?? null,
                'recorded_by' => $actor->id,
                'occurred_at' => $opts['occurred_at'] ?? now(),
            ]);
        });
    }

    /**
     * Credit a manager (company) account with a receivable collection.
     *
     * @param  array{
     *     description?: string|null,
     *     reference_no?: string|null,
     *     method?: string|null,
     *     reference_entity_type?: string|null,
     *     reference_entity_id?: int|null,
     *     occurred_at?: mixed
     * }  $opts
     */
    public function receiveReceivablePayment(
        MoneyAccount $account,
        string $amount,
        User $actor,
        array $opts = [],
    ): AccountTransaction {
        return DB::transaction(function () use ($account, $amount, $actor, $opts) {
            $account = MoneyAccount::lockForUpdate()->findOrFail($account->id);

            if (! $account->isManagerAccount()) {
                throw new \InvalidArgumentException('Receivable collections must be posted to a company (manager) account.');
            }

            if (! $account->is_active) {
                throw new \InvalidArgumentException('Cannot collect into an inactive account.');
            }

            $normalized = bcadd($amount, '0', 2);
            if (bccomp($normalized, '0', 2) !== 1) {
                throw new \InvalidArgumentException('Collection amount must be greater than zero.');
            }

            $balance = bcadd((string) $account->balance, $normalized, 2);
            $account->update(['balance' => $balance]);

            return AccountTransaction::create([
                'money_account_id' => $account->id,
                'type' => AccountTransactionType::ReceivablePayment,
                'amount' => $normalized,
                'balance_after' => $balance,
                'description' => $opts['description'] ?? 'Receivable collection',
                'reference_no' => $opts['reference_no'] ?? null,
                'method' => $opts['method'] ?? null,
                'reference_entity_type' => $opts['reference_entity_type'] ?? 'sale',
                'reference_entity_id' => $opts['reference_entity_id'] ?? null,
                'recorded_by' => $actor->id,
                'occurred_at' => $opts['occurred_at'] ?? now(),
            ]);
        });
    }

    /**
     * Reverse a finance disbursement (expense edit/delete).
     */
    public function reverseFinanceDisbursement(AccountTransaction $transaction, User $actor, string $reason): AccountTransaction
    {
        return DB::transaction(function () use ($transaction, $actor, $reason) {
            if ($transaction->type !== AccountTransactionType::Disbursement) {
                throw new \InvalidArgumentException('Only disbursement transactions can be reversed this way.');
            }

            $finance = MoneyAccount::lockForUpdate()->findOrFail($transaction->money_account_id);
            $amount = bcadd((string) $transaction->amount, '0', 2);
            $balance = bcadd((string) $finance->balance, $amount, 2);
            $finance->update(['balance' => $balance]);

            return AccountTransaction::create([
                'money_account_id' => $finance->id,
                'type' => AccountTransactionType::Adjustment,
                'amount' => $amount,
                'balance_after' => $balance,
                'description' => $reason,
                'reference_entity_type' => $transaction->reference_entity_type,
                'reference_entity_id' => $transaction->reference_entity_id,
                'recorded_by' => $actor->id,
                'occurred_at' => now(),
            ]);
        });
    }

    public function financeBalance(): string
    {
        $account = MoneyAccount::query()
            ->where('type', MoneyAccountType::Finance)
            ->orderBy('id')
            ->first();

        return $account ? bcadd((string) $account->balance, '0', 2) : '0.00';
    }

    public function managerBalance(bool $activeOnly = true): string
    {
        $total = '0.00';
        foreach ($this->managerAccounts($activeOnly) as $account) {
            $total = bcadd($total, (string) $account->balance, 2);
        }

        return $total;
    }

    /**
     * @return list<MoneyAccount>
     */
    public function managerAccounts(bool $activeOnly = true): array
    {
        return MoneyAccount::query()
            ->where('type', MoneyAccountType::Manager)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatAccount(MoneyAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'bank_name' => $account->bank_name,
            'type' => $account->type->value,
            'balance' => (string) $account->balance,
            'is_active' => $account->is_active,
            'notes' => $account->notes,
            'created_at' => $account->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatTransaction(AccountTransaction $tx): array
    {
        $tx->loadMissing(['account:id,name,type', 'relatedAccount:id,name,type', 'recorder:id,name']);

        return [
            'id' => $tx->id,
            'money_account_id' => $tx->money_account_id,
            'type' => $tx->type->value,
            'amount' => (string) $tx->amount,
            'balance_after' => (string) $tx->balance_after,
            'description' => $tx->description,
            'reference_no' => $tx->reference_no,
            'method' => $tx->method,
            'is_credit' => $tx->isCredit() || $tx->type === AccountTransactionType::Adjustment,
            'occurred_at' => $tx->occurred_at?->toIso8601String(),
            'account' => $tx->account ? [
                'id' => $tx->account->id,
                'name' => $tx->account->name,
                'type' => $tx->account->type->value,
            ] : null,
            'related_account' => $tx->relatedAccount ? [
                'id' => $tx->relatedAccount->id,
                'name' => $tx->relatedAccount->name,
                'type' => $tx->relatedAccount->type->value,
            ] : null,
            'recorder' => $tx->recorder ? [
                'id' => $tx->recorder->id,
                'name' => $tx->recorder->name,
            ] : null,
        ];
    }
}
